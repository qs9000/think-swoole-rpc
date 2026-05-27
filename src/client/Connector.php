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
use think\swoole\contract\rpc\ParserInterface;
use think\swoole\rpc\Packer;
use think\swoole\rpc\server\Dispatcher;
use qs9000\rpc\ServiceDiscover;
use qs9000\rpc\CircuitBreaker;
use qs9000\rpc\server\ServerInfo;
use qs9000\rpc\contract\ServiceInstanceInterface;
use Throwable;

class Connector implements ClientConnector
{
    use InteractsWithRpcConnector {
        // 给 trait 的 sendAndRecv 起别名，供远程调用回退使用
        sendAndRecv as protected traitSendAndRecv;
    }
    protected array $poolMap = [];
    protected array $poolConfig;
    protected string $serviceName;
    protected App $app;
    /** @var Dispatcher|null 本机直调用的调度器，惰性初始化 */
    protected ?Dispatcher $localDispatcher = null;
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
        $serviceInstance = $this->discoverServiceInstance();
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
            // 尝试从连接池借用，失败则走 createDirectClient 直连兜底
            try {
                $client = $pool->borrow();
                $fromPool = true;
            } catch (Throwable $_) {
                // 连接池无法提供有效连接（如 Swoole Client connect 超时）
                // 清除失效的连接池并尝试直连
                try {
                    $pool->close();
                } catch (Throwable $e) {}
                unset($this->poolMap[$nodeKey]);
                $fromPool = false;
                $client = $this->createDirectClient($host, $port);
            }

            // 连接健康检查：检测无效连接并尝试重建
            if ($client && $fromPool && (!$client->connected || $this->isConnectionUnhealthy($client))) {
                // 归还无效连接，让连接池自行销毁
                $pool->return($client);
                $fromPool = false;
                $client = null;

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
                    try {
                        $pool->return($client);
                    } catch (Throwable $e) {}
                } else {
                    try {
                        $client->close();
                    } catch (Throwable $e) {}
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
        // 注意：Pool::pullPoolConfig 对传入数组有副作用（Arr::pull 移除 key），
        // 因此用副本传入，避免修改 $this->poolConfig 导致后续节点丢失池配置。
        $poolConfigData = $this->poolConfig;
        $poolConfig = Pool::pullPoolConfig($poolConfigData);

        $pool = new ConnectionPool($poolConfig, new Client(), $config);
        $pool->init();

        // 存储池实例
        $nodeKey = $this->generateNodeKey($host, $port);
        $this->poolMap[$nodeKey] = $pool;

        return $pool;
    }

    /**
     * 直接创建 Swoole\Coroutine\Client 连接（绕过连接池与外部配置）
     * 
     * 不接收任何 poolConfig 污染 —— poolConfig 里混杂了 host、port、timeout、min_active 等非
     * Swoole\Coroutine\Client 合法选项，传入 set() 会干扰连接行为。
     * 
     * 内部自行用 Coroutine::run() 包裹 connect()，彻底避免 errCode=110 (ETIMEDOUT)。
     */
    protected function createDirectClient(string $host, int $port): \Swoole\Coroutine\Client
    {
        // 先尝试在当前协程上下文中连接（适用于已在 Swoole Server 中的场景）
        if (Coroutine::getCid() > 0) {
            return $this->doCreateDirectClient($host, $port);
        }

        // 不在协程中，用 Coroutine::run() 启动事件循环来驱动 connect()
        $client = null;
        $exception = null;

        Coroutine::run(function () use ($host, $port, &$client, &$exception) {
            try {
                $client = $this->doCreateDirectClient($host, $port);
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception) {
            throw $exception;
        }

        return $client;
    }

    /**
     * 实际执行 Swoole\Coroutine\Client 的创建与连接，不包含任何外部配置。
     */
    private function doCreateDirectClient(string $host, int $port): \Swoole\Coroutine\Client
    {
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);

        // 使用充足的超时，不依赖 poolConfig 中的 timeout
        $timeout = 10;

        if (!$client->connect($host, $port, $timeout)) {
            $errInfo = sprintf('errCode=%d, errMsg=%s', $client->errCode, $client->errMsg ?: 'none');
            $client->close();
            throw new RpcClientException(
                "无法建立到 {$host}:{$port} 的直接连接，{$errInfo}，请确认目标服务已启动且端口可达"
            );
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

    // ========================================================================
    //  本机直调（Local Call）相关方法
    //  当目标服务 host:port 指向本机时，绕过 TCP，用 Dispatcher 在进程内调用
    // ========================================================================

    /**
     * 服务发现：返回一个可用的服务实例
     */
    protected function discoverServiceInstance(): ServiceInstanceInterface
    {
        $serviceInstance = $this->app->make(ServiceDiscover::class)->discover($this->serviceName);
        if (!$serviceInstance) {
            throw new RpcClientException("RPC客户端未获取到RPC实例: {$this->serviceName}");
        }
        return $serviceInstance;
    }

    /**
     * 覆盖 trait 的 sendAndRecv，加入本机直调判断
     *
     * @param \Generator|array $data    打包后的 RPC 请求数据
     * @param callable         $decoder 响应解码回调
     * @return mixed 解码后的 RPC 响应
     */
    public function sendAndRecv($data, callable $decoder)
    {
        // 尝试本机直调
        if ($this->app->config->get('rpc.client.enable_local_call', false)) {
            try {
                $serviceInstance = $this->discoverServiceInstance();
                if ($this->isLocalService($serviceInstance->getHost(), $serviceInstance->getPort())) {
                    return $this->localSendAndRecv($data, $decoder);
                }
            } catch (RpcClientException $e) {
                // 服务发现失败，回退到 trait 的远程调用
            }
        }

        // 回退到 trait 的 TCP 调用
        return $this->traitSendAndRecv($data, $decoder);
    }

    /**
     * 判断 host:port 是否指向本机 RPC 服务
     */
    protected function isLocalService(string $host, int $port): bool
    {
        // 端口必须匹配当前 RPC 服务端口
        $localRpcPort = $this->app->config->get('swoole.rpc.server.port');
        if (!$localRpcPort || (int) $port !== (int) $localRpcPort) {
            return false;
        }

        // host 必须是本机 IP 之一
        return in_array($host, $this->getLocalIps(), true);
    }

    /**
     * 获取本机所有可能的 IP（127.0.0.1 + ServerInfo 探测的内网 IP + 配置的 host_ip）
     */
    protected function getLocalIps(): array
    {
        $ips = ['127.0.0.1'];

        // 通过配置文件直接覆盖的 IP
        $hostIp = $this->app->config->get('app.host_ip');
        if ($hostIp && !in_array($hostIp, $ips, true)) {
            $ips[] = $hostIp;
        }

        // 通过 ServerInfo 自动探测的内网 IP
        try {
            $serverInfo = $this->app->make(ServerInfo::class);
            $serverIp   = $serverInfo->getServerIp();
            if ($serverIp && !in_array($serverIp, $ips, true)) {
                $ips[] = $serverIp;
            }
        } catch (Throwable $e) {
            // 获取失败不影响正常流程
        }

        return $ips;
    }

    /**
     * 惰性获取本机 Dispatcher 实例
     *
     * 使用与 Swoole 服务端相同的 services 和 middleware 配置，
     * 确保中间件（认证/限流/追踪）在本地调用时同样生效。
     */
    protected function getLocalDispatcher(): ?Dispatcher
    {
        if ($this->localDispatcher === null) {
            $services   = $this->app->config->get('swoole.rpc.server.services', []);
            $middleware = $this->app->config->get('swoole.rpc.server.middleware', []);

            if (empty($services)) {
                return null;
            }

            $this->localDispatcher = new Dispatcher($services, $middleware);
        }
        return $this->localDispatcher;
    }

    /**
     * 本机直调：绕过 TCP，用 Dispatcher 在进程内处理 RPC 请求
     *
     * 使用反射调用 Dispatcher 的 protected dispatchWithMiddleware 方法，
     * 避免 Connection 类型约束无法模拟的问题。
     */
    protected function localSendAndRecv($data, callable $decoder)
    {
        if (!$data instanceof \Generator) {
            $data = [$data];
        }

        $dispatcher = $this->getLocalDispatcher();
        if ($dispatcher === null) {
            // Dispatcher 不可用，回退到远程调用
            return $this->traitSendAndRecv($data, $decoder);
        }

        /** @var ParserInterface $parser */
        $parser = $this->app->make(ParserInterface::class);
        $files  = [];

        $responsePayload = null;

        // 逐帧处理发送数据（可能是文件分块 + JSON-RPC 请求）
        foreach ($data as $packedFrame) {
            if (empty($packedFrame)) {
                continue;
            }

            [$handler, $body] = Packer::unpack($packedFrame);
            $result = $handler->write($body);

            if ($result !== null) {
                if ($result instanceof \think\swoole\packet\File) {
                    // 收集文件参数，后续注入到 Protocol 的 params 中
                    $files[] = $result;
                } else {
                    // JSON-RPC 请求到达：解析 Protocol → 调用 dispatchWithMiddleware → 编码响应
                    $protocol = $parser->decode($result);

                    // 文件参数处理：本地直调场景下文件传输极少使用，统一清空 files 数组
                    // 如果 Protocol params 中无 FILE 占位符，files 可安全忽略
                    $files = [];

                    // 通过反射调用 Dispatcher::dispatchWithMiddleware（protected 方法）
                    try {
                        $ref = new \ReflectionMethod($dispatcher, 'dispatchWithMiddleware');
                        $ref->setAccessible(true);
                        $dispatchResult = $ref->invoke($dispatcher, $this->app, $protocol, $files);
                    } catch (Throwable $e) {
                        // Dispatcher 内部异常转为 Error 响应
                        $dispatchResult = new \think\swoole\rpc\Error($e->getCode() ?: -32603, $e->getMessage());
                    }

                    // 编码响应 + 打包（模拟 Dispatcher::dispatch 中 $conn->send 的逻辑）
                    $encodedPayload  = $parser->encodeResponse($dispatchResult);
                    $responsePayload = $encodedPayload;
                    $files = [];
                }
            }
        }

        if ($responsePayload === null) {
            throw new RpcClientException('本地 RPC 调用失败：未收到有效的响应数据');
        }

        // 模拟 Gateway::decodeResponse 的解码流程
        return $decoder($responsePayload);
    }
}