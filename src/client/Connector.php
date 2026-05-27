<?php

declare(strict_types=1);

namespace qs9000\rpc\client;

use think\swoole\exception\RpcClientException;
use think\swoole\Pool;
use think\swoole\pool\Client;
use think\swoole\rpc\client\Connector as ClientConnector;
use Smf\ConnectionPool\ConnectionPool;
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

        try {
            $client = $pool->borrow();

            // 连接健康检查：检测无效连接并尝试重建
            if (!$client || !$client->connected || $this->isConnectionUnhealthy($client)) {
                // 归还无效连接，让连接池自行销毁
                if ($client) {
                    $pool->return($client);
                    $client = null;
                }
                $pool->close();
                unset($this->poolMap[$nodeKey]);
                $this->createPool($host, $port);
                $pool = $this->poolMap[$nodeKey];
                $client = $pool->borrow();
                if (!$client || !$client->connected) {
                    throw new RpcClientException("无法建立到 {$nodeKey} 的连接");
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
            // 必须归还连接，即使已断开——连接池会自行处理断开连接的清理
            // 否则连接池计数不会减少，导致连接泄漏
            if ($client !== null) {
                $pool->return($client);
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

        // 存储池实例
        $nodeKey = $this->generateNodeKey($host, $port);
        $this->poolMap[$nodeKey] = $pool;

        return $pool;
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