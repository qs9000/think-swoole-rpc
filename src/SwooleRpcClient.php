<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\RpcClientInterface;
use qs9000\rpc\contract\ServiceInstanceInterface;
use Swoole\Coroutine\Client;
use think\swoole\exception\RpcClientException;
use think\swoole\exception\RpcResponseException;
use think\swoole\rpc\Error;
use think\swoole\rpc\JsonParser;
use think\swoole\rpc\Packer;
use think\swoole\rpc\Protocol;
use Throwable;

/**
 * Swoole RPC 客户端（TCP 高性能）
 *
 * 基于 think-swoole RPC 协议实现，支持：
 * - 服务发现与负载均衡
 * - 熔断器保护
 * - 连接池管理（带最大连接数限制和健康检查）
 * - 智能重试（失败时自动切换实例）
 * - 指数退避策略
 *
 * @package qs9000\rpc
 */
class SwooleRpcClient implements RpcClientInterface
{
    /** @var int 退避基数（微秒）- 100ms */
    const BACKOFF_BASE_US = 100_000;
    
    /** @var int 最大退避时间（微秒）- 1s */
    const BACKOFF_MAX_US = 1_000_000;
    
    /** @var int 最小超时时间（毫秒） */
    const MIN_TIMEOUT_MS = 100;
    
    /** @var int 最大重试次数 */
    const MAX_RETRY_TIMES = 5;
    
    /** @var int 最小重试次数 */
    const MIN_RETRY_TIMES = 1;

    /** @var ServiceDiscovery 服务发现器 */
    protected ServiceDiscovery $discovery;

    /** @var CircuitBreaker 熔断器 */
    protected CircuitBreaker $circuitBreaker;

    /** @var string 负载均衡策略 */
    protected string $loadBalancer = 'random';

    /** @var int 调用超时时间（毫秒） */
    protected int $timeout = 5000;

    /** @var int 连接超时时间（毫秒） */
    protected int $connectTimeout = 1000;

    /** @var int 最大重试次数 */
    protected int $retryTimes = 2;

    /** @var int 连接池最大连接数 */
    protected int $maxConnections = 20;

    /** @var int 连接空闲超时时间（秒）- 超过此时间未使用的连接将被关闭 */
    protected int $connectionIdleTimeout = 300; // 5分钟

    /** @var array 连接池 [instanceId => Client] */
    protected array $pools = [];

    /** @var array 连接最后使用时间 [instanceId => timestamp] */
    protected array $connectionLastUsed = [];

    /** @var JsonParser JSON 解析器（复用避免重复创建） */
    protected JsonParser $parser;

    /** @var Middleware|null 中间件管理器 */
    protected ?Middleware $middleware = null;

    public function __construct(
        ?ServiceDiscovery $discovery = null,
        ?CircuitBreaker $circuitBreaker = null,
        ?\think\App $app = null
    ) {
        $this->discovery = $discovery ?? new ServiceDiscovery();
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker();
        $this->parser = new JsonParser();

        // 从配置加载参数
        if (function_exists('config')) {
            $this->loadBalancer = config('rpc.discovery.loadbalancer', 'random');
            $this->timeout = (int) config('rpc.timeout', 5000);
            $this->connectTimeout = (int) config('rpc.connection.connect_timeout', 1000);
            $this->retryTimes = (int) config('rpc.tries', 2);
            $this->maxConnections = (int) config('rpc.connection.max_connections', 20);
            $this->connectionIdleTimeout = (int) config('rpc.connection.idle_timeout', 300);
            
            // 初始化中间件
            $middlewares = config('rpc.middleware', []);
            if (!empty($middlewares) && $app) {
                $this->middleware = Middleware::make($app, $middlewares);
            }
        }

        $this->discovery->setLoadBalancerStrategy($this->loadBalancer);
    }

    /**
     * 调用远程服务（自动服务发现和负载均衡）
     *
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     * @throws RpcException
     */
    public function call(string $service, string $method, array $params = [], ?string $version = null): mixed
    {
        // 1. 熔断器检查
        if (!$this->circuitBreaker->allowRequest($service)) {
            throw new RpcException(
                "Circuit breaker is open for service: {$service}",
                -32000
            );
        }

        // 2. 服务发现
        $instance = $this->discovery->discover($service);
        if (!$instance) {
            $this->circuitBreaker->recordFailure($service);
            throw new RpcException(
                "No available instance for service: {$service}",
                -32001
            );
        }

        // 3. 如果有中间件，通过中间件管道执行
        if ($this->middleware) {
            return $this->callWithMiddleware($instance, $service, $method, $params, $version);
        }

        // 4. 直接执行调用（带重试）
        return $this->callWithRetry($instance, $service, $method, $params, $version);
    }

    /**
     * 通过中间件管道执行调用
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     * @throws RpcException
     */
    protected function callWithMiddleware(
        ServiceInstanceInterface $instance,
        string $service,
        string $method,
        array $params,
        ?string $version
    ): mixed {
        // 构建接口名
        $interface = $version ? "{$service}.{$version}" : $service;
        
        // 创建协议对象
        $protocol = Protocol::make($interface, $method, $params);

        try {
            // 通过中间件管道执行
            $result = $this->middleware->pipeline()
                ->then(function (Protocol $protocol) use ($instance, $service, $method, $version) {
                    // 在中间件执行完毕后，实际调用服务
                    return $this->executeCallWithProtocol(
                        $instance,
                        $protocol,
                        $service,
                        $method,
                        $version
                    );
                })
                ->send($protocol);

            $this->circuitBreaker->recordSuccess($service);
            return $result;
            
        } catch (RpcResponseException $e) {
            $this->circuitBreaker->recordFailure($service);
            throw $e;
            
        } catch (Throwable $e) {
            $this->circuitBreaker->recordFailure($service);
            throw $this->wrapException($e, $service, $method);
        }
    }

    /**
     * 使用协议对象执行调用
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param Protocol $protocol 协议对象
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param string|null $version 版本号
     * @return mixed
     * @throws Throwable
     */
    protected function executeCallWithProtocol(
        ServiceInstanceInterface $instance,
        Protocol $protocol,
        string $service,
        string $method,
        ?string $version
    ): mixed {
        // 从协议对象获取参数
        $params = $protocol->getParams();
        
        // 执行实际调用
        return $this->executeCall($instance, $service, $method, $params, $version);
    }

    /**
     * 调用指定服务实例（不经过服务发现）
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     * @throws RpcException
     */
    public function callInstance(
        ServiceInstanceInterface $instance,
        string $service,
        string $method,
        array $params = [],
        ?string $version = null
    ): mixed {
        try {
            $result = $this->executeCall($instance, $service, $method, $params, $version);
            $this->circuitBreaker->recordSuccess($service);
            return $result;
        } catch (Throwable $e) {
            $this->circuitBreaker->recordFailure($service);
            $this->handleCallFailure($instance, $e);
            throw $this->wrapException($e, $service, $method);
        }
    }

    /**
     * 带重试的调用（失败时自动切换实例）
     *
     * @param ServiceInstanceInterface $initialInstance 初始实例
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     * @throws RpcException
     */
    protected function callWithRetry(
        ServiceInstanceInterface $initialInstance,
        string $service,
        string $method,
        array $params,
        ?string $version
    ): mixed {
        $currentInstance = $initialInstance;
        $lastException = null;

        for ($attempt = 0; $attempt < $this->retryTimes; $attempt++) {
            try {
                $result = $this->executeCall($currentInstance, $service, $method, $params, $version);
                
                // 成功：记录成功并返回
                $this->circuitBreaker->recordSuccess($service);
                return $result;
                
            } catch (RpcResponseException $e) {
                // RPC 响应错误（业务逻辑错误），不应重试，直接抛出
                $this->circuitBreaker->recordFailure($service);
                throw $e;
                
            } catch (RpcClientException $e) {
                // 客户端错误（网络问题、连接失败等），可以重试
                $lastException = $e;
                $this->circuitBreaker->recordFailure($service);
                
                // 处理失败（关闭连接等）
                $this->handleCallFailure($currentInstance, $e);

                // 如果还有重试机会，等待后切换实例重试
                if ($attempt < $this->retryTimes - 1) {
                    // 指数退避
                    usleep($this->calculateBackoff($attempt));
                    
                    // 重新发现服务实例（可能切换到其他健康实例）
                    $newInstance = $this->discovery->discover($service);
                    if ($newInstance && $newInstance->getId() !== $currentInstance->getId()) {
                        $currentInstance = $newInstance;
                    }
                }
                
            } catch (Throwable $e) {
                // 其他未知错误，记录并尝试重试
                $lastException = $e;
                $this->circuitBreaker->recordFailure($service);
                
                // 处理失败
                $this->handleCallFailure($currentInstance, $e);

                // 如果还有重试机会
                if ($attempt < $this->retryTimes - 1) {
                    usleep($this->calculateBackoff($attempt));
                    
                    $newInstance = $this->discovery->discover($service);
                    if ($newInstance && $newInstance->getId() !== $currentInstance->getId()) {
                        $currentInstance = $newInstance;
                    }
                }
            }
        }

        // 所有重试都失败
        throw $this->wrapException($lastException, $service, $method, $this->retryTimes);
    }

    /**
     * 执行实际的 RPC 调用
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     * @throws Throwable
     */
    protected function executeCall(
        ServiceInstanceInterface $instance,
        string $service,
        string $method,
        array $params,
        ?string $version
    ): mixed {
        // 1. 构建接口名（支持版本）
        $interface = $version ? "{$service}.{$version}" : $service;

        // 2. 构建协议对象
        $protocol = Protocol::make($interface, $method, $params);

        // 3. 编码请求（JSON-RPC 2.0）
        $requestData = $this->parser->encode($protocol);

        // 4. 封包（添加长度头）
        $packet = Packer::pack($requestData);

        // 5. 发送并接收响应
        $responseData = $this->sendAndReceive($instance, $packet);

        // 6. 解包
        $unpacked = Packer::unpack($responseData);
        if (empty($unpacked) || !isset($unpacked[1])) {
            throw new RpcClientException('Invalid response format');
        }

        // 7. 解码响应
        $result = $this->parser->decodeResponse($unpacked[1]);

        // 8. 检查是否为错误响应
        if ($result instanceof Error) {
            throw new RpcResponseException($result);
        }

        return $result;
    }

    /**
     * 发送请求并接收响应
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param string $data 已封包的数据
     * @return string 原始响应数据
     * @throws RpcClientException
     */
    protected function sendAndReceive(ServiceInstanceInterface $instance, string $data): string
    {
        // 获取连接（从连接池或新建）
        $client = $this->getConnection($instance);

        // 发送请求
        if ($client->send($data) === false) {
            $this->closeConnection($instance);
            throw new RpcClientException(
                sprintf('Send failed to %s:%d', $instance->getHost(), $instance->getPort())
            );
        }

        // 接收响应（带超时）
        $response = $this->recvWithUnpack($client);
        
        if ($response === false) {
            $this->closeConnection($instance);
            throw new RpcClientException(
                sprintf('Receive timeout from %s:%d (timeout: %dms)', 
                    $instance->getHost(), 
                    $instance->getPort(),
                    $this->timeout
                )
            );
        }

        return $response;
    }

    /**
     * 接收并解包响应（改进的粘包处理）
     *
     * @param Client $client Swoole 客户端
     * @return string|false 解包后的数据
     */
    protected function recvWithUnpack(Client $client): string|false
    {
        $timeoutSec = $this->timeout / 1000;
        $startTime = microtime(true);
        $buffer = '';

        while (true) {
            // 使用较短的超时时间进行轮询检查
            $data = $client->recv(0.1);

            if ($data !== false && strlen($data) > 0) {
                // 累积数据到缓冲区
                $buffer .= $data;
                
                // 尝试解包
                try {
                    $unpacked = Packer::unpack($buffer);
                    if (!empty($unpacked) && isset($unpacked[1])) {
                        // 成功解包，返回完整数据
                        return $buffer;
                    }
                } catch (Throwable $e) {
                    // 解包失败，可能是数据不完整，继续接收
                    RpcLogger::debug(
                        'Unpack failed, waiting for more data',
                        ['error' => $e->getMessage(), 'buffer_length' => strlen($buffer)]
                    );
                }
                
                // 如果缓冲区过大（超过 10MB），可能是错误数据，放弃
                if (strlen($buffer) > 10 * 1024 * 1024) {
                    RpcLogger::warning(
                        'Buffer size exceeded limit, discarding',
                        ['buffer_length' => strlen($buffer)]
                    );
                    return false;
                }
            }

            // 检查总超时
            if ((microtime(true) - $startTime) >= $timeoutSec) {
                RpcLogger::warning(
                    'Receive timeout',
                    [
                        'timeout' => $this->timeout,
                        'buffer_length' => strlen($buffer),
                    ]
                );
                return false;
            }

            // 检查连接状态
            if (!$client->isConnected()) {
                RpcLogger::warning('Connection lost during receive');
                return false;
            }
        }
    }

    /**
     * 获取连接（连接池管理）
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @return Client
     * @throws RpcClientException
     */
    protected function getConnection(ServiceInstanceInterface $instance): Client
    {
        $key = $instance->getId();

        // 1. 尝试复用已有连接
        if (isset($this->pools[$key])) {
            $client = $this->pools[$key];
            
            // 检查连接是否健康且未超时
            if ($client->isConnected() && !$this->isConnectionIdle($key)) {
                // 更新最后使用时间
                $this->connectionLastUsed[$key] = time();
                return $client;
            }
            
            // 连接已断开或空闲超时，清理
            $this->removeConnection($key);
        }

        // 2. 检查连接池大小限制
        if (count($this->pools) >= $this->maxConnections) {
            $this->evictLeastUsedConnection();
        }

        // 3. 创建新连接
        return $this->createConnection($instance);
    }

    /**
     * 检查连接是否空闲超时
     *
     * @param string $key 连接键
     * @return bool true=已超时
     */
    protected function isConnectionIdle(string $key): bool
    {
        if (!isset($this->connectionLastUsed[$key])) {
            return true;
        }
        
        $idleTime = time() - $this->connectionLastUsed[$key];
        return $idleTime > $this->connectionIdleTimeout;
    }

    /**
     * 创建新连接
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @return Client
     * @throws RpcClientException
     */
    protected function createConnection(ServiceInstanceInterface $instance): Client
    {
        $client = new Client(SWOOLE_SOCK_TCP);

        // 配置连接参数
        $client->set([
            'timeout' => $this->timeout / 1000,
            'connect_timeout' => $this->connectTimeout / 1000,
            'open_eof_check' => true,      // 启用 EOF 检测
            'package_eof' => "\r\n",       // 设置结束符
        ]);

        // 连接服务器
        if (!$client->connect(
            $instance->getHost(),
            $instance->getPort(),
            $this->connectTimeout / 1000
        )) {
            $error = $client->errCode;
            $errorMsg = $client->errMsg ?? 'Unknown error';
            throw new RpcClientException(
                sprintf(
                    'Connect failed to %s:%d (errno: %d, msg: %s)',
                    $instance->getHost(),
                    $instance->getPort(),
                    $error,
                    $errorMsg
                ),
                -32002
            );
        }

        // 存入连接池
        $this->pools[$instance->getId()] = $client;
        
        // 记录连接创建时间
        $this->connectionLastUsed[$instance->getId()] = time();

        return $client;
    }

    /**
     * 移除连接
     *
     * @param string $key 连接键
     */
    protected function removeConnection(string $key): void
    {
        if (isset($this->pools[$key])) {
            try {
                $this->pools[$key]->close();
            } catch (Throwable $e) {
                // 忽略关闭错误
            }
            unset($this->pools[$key]);
            
            // 同时清理使用时间记录
            unset($this->connectionLastUsed[$key]);
        }
    }

    /**
     * 关闭连接
     *
     * @param ServiceInstanceInterface $instance 服务实例
     */
    protected function closeConnection(ServiceInstanceInterface $instance): void
    {
        $this->removeConnection($instance->getId());
    }

    /**
     * 驱逐最少使用的连接（当连接池满时）
     * 
     * 基于最后使用时间进行驱逐，优先移除最久未使用的连接
     */
    protected function evictLeastUsedConnection(): void
    {
        if (empty($this->pools)) {
            return;
        }
        
        // 首先清理所有空闲超时的连接
        $this->cleanupIdleConnections();
        
        // 如果清理后仍然超过限制，再驱逐最旧的连接
        if (count($this->pools) >= $this->maxConnections) {
            $oldestKey = null;
            $oldestTime = PHP_INT_MAX;
            
            foreach ($this->connectionLastUsed as $key => $lastUsed) {
                if ($lastUsed < $oldestTime && isset($this->pools[$key])) {
                    $oldestTime = $lastUsed;
                    $oldestKey = $key;
                }
            }
            
            // 如果没有找到记录，则移除第一个连接（向后兼容）
            if ($oldestKey === null) {
                $oldestKey = array_key_first($this->pools);
            }
            
            if ($oldestKey !== null) {
                $this->removeConnection($oldestKey);
            }
        }
    }

    /**
     * 清理所有空闲超时的连接
     */
    protected function cleanupIdleConnections(): void
    {
        $now = time();
        $cleanedCount = 0;
        
        foreach ($this->connectionLastUsed as $key => $lastUsed) {
            $idleTime = $now - $lastUsed;
            
            if ($idleTime > $this->connectionIdleTimeout && isset($this->pools[$key])) {
                $this->removeConnection($key);
                $cleanedCount++;
                
                if (RpcLogger::isDebug()) {
                    RpcLogger::debug(
                        'Cleaned up idle connection',
                        [
                            'key' => $key,
                            'idle_time' => $idleTime,
                        ]
                    );
                }
            }
        }
        
        if ($cleanedCount > 0 && RpcLogger::isDebug()) {
            RpcLogger::debug(
                'Cleaned up idle connections',
                ['count' => $cleanedCount]
            );
        }
    }

    /**
     * 处理调用失败
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param Throwable $exception 异常
     */
    protected function handleCallFailure(
        ServiceInstanceInterface $instance,
        Throwable $exception
    ): void {
        // 如果是连接相关错误，关闭连接
        if ($exception instanceof RpcClientException) {
            $this->closeConnection($instance);
        }
    }

    /**
     * 包装异常
     *
     * @param Throwable $exception 原始异常
     * @param string $service 服务名
     * @param string $method 方法名
     * @param int|null $tries 重试次数
     * @return RpcException
     */
    protected function wrapException(
        Throwable $exception,
        string $service,
        string $method,
        ?int $tries = null
    ): RpcException {
        $message = $exception->getMessage();
        
        if ($tries !== null && $tries > 1) {
            $message = "RPC call failed after {$tries} tries: {$message}";
        }
        
        return new RpcException(
            $message,
            $exception->getCode() ?: -32099,
            $exception
        );
    }

    /**
     * 计算退避时间（微秒）
     * 使用指数退避算法：100ms * 2^attempt，最大 1s
     *
     * @param int $attempt 重试次数（从 0 开始）
     * @return int 退避时间（微秒）
     */
    protected function calculateBackoff(int $attempt): int
    {
        return (int) min(self::BACKOFF_BASE_US * pow(2, $attempt), self::BACKOFF_MAX_US);
    }

    /**
     * 设置调用超时时间
     *
     * @param int $timeout 超时时间（毫秒）
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(self::MIN_TIMEOUT_MS, $timeout);
        return $this;
    }

    /**
     * 设置连接超时时间
     *
     * @param int $timeout 连接超时（毫秒）
     * @return self
     */
    public function setConnectTimeout(int $timeout): self
    {
        $this->connectTimeout = max(self::MIN_TIMEOUT_MS, $timeout);
        return $this;
    }

    /**
     * 设置负载均衡策略
     *
     * @param string $strategy 策略名称
     * @return self
     */
    public function setLoadBalancer(string $strategy): self
    {
        $this->loadBalancer = $strategy;
        $this->discovery->setLoadBalancerStrategy($strategy);
        return $this;
    }

    /**
     * 设置重试次数
     *
     * @param int $times 重试次数
     * @return self
     */
    public function setRetryTimes(int $times): self
    {
        $this->retryTimes = max(self::MIN_RETRY_TIMES, min(self::MAX_RETRY_TIMES, $times));
        return $this;
    }

    /**
     * 设置最大连接数
     *
     * @param int $maxConnections 最大连接数
     * @return self
     */
    public function setMaxConnections(int $maxConnections): self
    {
        $this->maxConnections = max(1, $maxConnections);
        return $this;
    }

    /**
     * 设置连接空闲超时时间
     *
     * @param int $timeout 空闲超时时间（秒）
     * @return self
     */
    public function setConnectionIdleTimeout(int $timeout): self
    {
        $this->connectionIdleTimeout = max(60, $timeout); // 最小 60 秒
        return $this;
    }

    /**
     * 获取服务发现器
     *
     * @return ServiceDiscovery
     */
    public function getDiscovery(): ServiceDiscovery
    {
        return $this->discovery;
    }

    /**
     * 获取熔断器
     *
     * @return CircuitBreaker
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * 获取中间件管理器
     *
     * @return Middleware|null
     */
    public function getMiddleware(): ?Middleware
    {
        return $this->middleware;
    }

    /**
     * 设置中间件管理器
     *
     * @param Middleware $middleware
     * @return self
     */
    public function setMiddleware(Middleware $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * 添加中间件
     *
     * @param mixed $middleware 中间件类名、数组或闭包
     * @return self
     */
    public function middleware(mixed $middleware): self
    {
        if (!$this->middleware) {
            // 如果没有初始化中间件，创建一个空的
            $this->middleware = new Middleware();
        }
        
        $this->middleware->add($middleware);
        return $this;
    }

    /**
     * 批量添加中间件
     *
     * @param array $middlewares
     * @return self
     */
    public function use(array $middlewares): self
    {
        if (!$this->middleware) {
            $this->middleware = new Middleware();
        }
        
        $this->middleware->use($middlewares);
        return $this;
    }

    /**
     * 获取连接池统计信息
     *
     * @return array
     */
    public function getPoolStats(): array
    {
        $now = time();
        $activeCount = 0;
        $idleCount = 0;
        
        foreach ($this->pools as $key => $client) {
            if ($client->isConnected()) {
                $activeCount++;
                
                // 检查是否空闲
                if (isset($this->connectionLastUsed[$key])) {
                    $idleTime = $now - $this->connectionLastUsed[$key];
                    if ($idleTime > $this->connectionIdleTimeout) {
                        $idleCount++;
                    }
                }
            }
        }
        
        return [
            'total_connections' => count($this->pools),
            'max_connections' => $this->maxConnections,
            'active_connections' => $activeCount,
            'idle_connections' => $idleCount,
            'utilization_rate' => count($this->pools) > 0 
                ? round(($activeCount / count($this->pools)) * 100, 2) 
                : 0,
        ];
    }

    /**
     * 关闭所有连接
     */
    public function close(): void
    {
        foreach ($this->pools as $key => $client) {
            $this->removeConnection($key);
        }
        $this->pools = [];
    }

    /**
     * 析构函数：确保资源释放
     */
    public function __destruct()
    {
        $this->close();
    }
}
