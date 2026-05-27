<?php

declare(strict_types=1);

namespace qs9000\rpc\client;

use think\swoole\exception\RpcClientException;
use think\swoole\Pool;
use think\swoole\pool\Client;
use think\swoole\rpc\client\Connector as ClientConnector;
use Smf\ConnectionPool\ConnectionPool;
use Swoole\Coroutine;
use think\App;
use think\swoole\concerns\InteractsWithRpcConnector;
use qs9000\rpc\ServiceDiscover;
use qs9000\rpc\CircuitBreaker;
use Throwable;

class Connector implements ClientConnector
{
    use InteractsWithRpcConnector;
    protected array $poolMap = [];
    protected array $poolConfig;
    protected string $serviceName;
    protected App $app;
    public function __construct(App $app, string $serviceName)
    {
        $this->app = $app;
        // 获取配置，如果不存在则使用空数组，确保后续处理安全
        $this->poolConfig = $this->app->config->get('rpc.client.pool') ?? [];
        $this->serviceName = $serviceName;
    }


    /**
     * 使用 RPC 客户端执行回调函数
     *
     * 该方法负责服务发现、熔断器检查、连接池管理以及客户端资源的生命周期管理。
     * 它确保在回调执行前后正确记录熔断器状态，并保证客户端连接在使用后被归还到连接池中。
     *
     * @param callable $callback 接收 Swoole Coroutine Client 实例作为参数的回调函数
     * @return mixed 回调函数的返回值
     * @throws RpcClientException 当无法获取服务实例或熔断器打开时抛出异常
     */
    public function runWithClient($callback)
    {
        // 确保在 Swoole 协程环境中运行，否则 Swoole\Coroutine\Client::connect() 会超时 (errCode=110)
        if (Coroutine::getCid() === -1) {
            return Coroutine\run(function () use ($callback) {
                return $this->runWithClient($callback);
            });
        }

        // 服务发现：获取目标服务的实例信息
        $serviceInstance = $this->app->make(ServiceDiscover::class)->discover($this->serviceName);
        if (!$serviceInstance) {
            throw new RpcClientException("RPC客户端未获取到RPC实例: {$this->serviceName}");
        }
        $host = $serviceInstance->getHost();
        $port = $serviceInstance->getPort();
        $nodeKey = $this->generateNodeKey($host, $port);

        // 熔断器检查：判断当前节点是否允许发起请求
        $circuitBreaker = $this->app->make(CircuitBreaker::class);
        $circuitBreakerName = "{$this->serviceName}_{$nodeKey}";
        if (!$circuitBreaker->allowRequest($circuitBreakerName)) {
            throw new RpcClientException("服务 {$this->serviceName} 的节点 {$nodeKey} 当前不可用，熔断器已打开");
        }

        // 连接池管理：确保对应节点的连接池已创建，并借用一个客户端连接
        if (!isset($this->poolMap[$nodeKey])) {
            $this->createPool($host, $port);
        }
        $pool = $this->poolMap[$nodeKey];


        /** @var \Swoole\Coroutine\Client|null $client */
        $client = null;

        // 标记客户端是否来自连接池，决定 finally 中是归还还是直接关闭
        $fromPool = false;

        try {
            $client = $pool->borrow();
            $fromPool = true;

            // 连接健康检查：检测无效连接并尝试重建
            if (!$client || !$client->connected || $this->isConnectionUnhealthy($client)) {
                // 归还无效连接，让连接池自行销毁
                if ($client) {
                    $pool->return($client);
                    $fromPool = false;
                    $client = null;
                }
                $pool->close();
                unset($this->poolMap[$nodeKey]);
                $this->createPool($host, $port);
                $pool = $this->poolMap[$nodeKey];
                $client = $pool->borrow();
                $fromPool = true;
                if (!$client || !$client->connected) {
                    // 连接池两次都失败，使用直接连接兜底
                    if ($client) {
                        $pool->return($client);
                        $client = null;
                    }
                    $fromPool = false;
                    $client = $this->createDirectClient($host, $port);
                }
            }

            // 执行用户回调
            $result = $callback($client);
            $circuitBreaker->recordSuccess($circuitBreakerName);
            return $result;
        } catch (Throwable $e) {
            // 熔断器记录失败状态并重新抛出
            if (isset($circuitBreakerName)) {
                $circuitBreaker->recordFailure($circuitBreakerName);
            }
            throw $e;
        } finally {
            // 从连接池借用的必须归还，防止连接泄漏
            // 直接创建的客户端直接关闭
            if ($client !== null) {
                if ($fromPool) {
                    $pool->return($client);
                } else {
                    $client->close();
                }
            }
        }
    }

    /**
     * 检查连接是否健康
     * 
     * @param \Swoole\Coroutine\Client $client 客户端连接
     * @return bool 连接是否健康
     */
    protected function isConnectionUnhealthy(\Swoole\Coroutine\Client $client): bool
    {
        // 检查连接是否仍然活跃
        if (!$client->connected) {
            return true;
        }

        // 可以在这里添加更多的健康检查逻辑，如发送心跳包等
        // 目前我们仅检查连接状态
        return false;
    }

    /**
     * 创建连接池
     *
     * @param string $host
     * @param int $port
     * @return ConnectionPool
     */
    protected function createPool(string $host, int $port): ConnectionPool
    {
        // 构建连接配置
        $config = [
            'host' => $host,
            'port' => $port,
        ];

        // 拉取池配置并创建连接池
        // 注意：假设 Pool::pullPoolConfig 能处理空数组或合并默认值
        $poolConfig = Pool::pullPoolConfig($this->poolConfig);

        $pool = new ConnectionPool($poolConfig, new Client(), $config);
        $pool->init();

        // 存储池实例
        $nodeKey = $this->generateNodeKey($host, $port);
        $this->poolMap[$nodeKey] = $pool;

        return $pool;
    }

    /**
     * 直接创建 Swoole\Coroutine\Client 连接（绕过连接池）
     * 作为连接池无法提供有效连接时的兜底方案，参考 think-swoole Gateway 的实现
     */
    protected function createDirectClient(string $host, int $port): \Swoole\Coroutine\Client
    {
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);

        $timeout = $this->poolConfig['timeout'] ?? 5;
        $client->set($this->poolConfig);

        if (!$client->connect($host, $port, $timeout)) {
            $errInfo = sprintf('errCode=%d, errMsg=%s', $client->errCode, $client->errMsg ?: 'none');
            $client->close();
            throw new RpcClientException("无法建立到 {$host}:{$port} 的直接连接，{$errInfo}，请确认 Swoole Coroutine 环境正常且目标服务已启动");
        }

        return $client;
    }

    /**
     * 生成节点唯一键
     *
     * @param string $host
     * @param int $port
     * @return string
     */
    protected function generateNodeKey(string $host, int $port): string
    {
        return "{$host}:{$port}";
    }


    /**
     * 关闭连接池中所有的连接并清空池映射。
     *
     * @return void
     */
    public function close(): void
    {
        // 遍历所有连接池并执行关闭操作
        foreach ($this->poolMap as $pool) {
            $pool->close();
        }
        // 清空连接池映射数组
        $this->poolMap = [];
    }
}