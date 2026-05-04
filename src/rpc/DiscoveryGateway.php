<?php

declare(strict_types=1);

namespace qs9000\rpc\rpc;

use think\swoole\rpc\Protocol;
use think\swoole\rpc\Error;
use think\swoole\exception\RpcResponseException;
use think\swoole\rpc\Packer;
use think\swoole\rpc\JsonParser;
use Swoole\Coroutine\Client;
use Throwable;
use qs9000\rpc\ServiceDiscovery;
use qs9000\rpc\CircuitBreaker;
use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 支持动态服务发现的 RPC Gateway
 * 
 * 解决 think-swoole 内置 Gateway 的配置固定问题：
 * - 不在启动时固定服务地址
 * - 每次调用时通过 ServiceDiscovery 动态获取可用实例
 * - 支持运行时新注册的服务
 * - 集成负载均衡和熔断保护
 * - 支持连接池管理
 * 
 * @package qs9000\rpc\rpc
 */
class DiscoveryGateway
{
    /** @var ServiceDiscovery 服务发现器 */
    protected ServiceDiscovery $discovery;

    /** @var CircuitBreaker 熔断器 */
    protected CircuitBreaker $circuitBreaker;

    /** @var string 负载均衡策略 */
    protected string $loadBalancerStrategy = 'random';

    /** @var int 调用超时时间（秒） */
    protected int $timeout = 5;

    /** @var int 连接超时时间（秒） */
    protected int $connectTimeout = 1;

    /** @var int 最大重试次数 */
    protected int $tries = 2;

    /** @var JsonParser JSON 解析器（复用） */
    protected JsonParser $parser;

    /** @var array 连接池 [instanceId => Client] */
    protected array $connectionPool = [];

    public function __construct(
        ServiceDiscovery $discovery,
        CircuitBreaker $circuitBreaker,
        string $loadBalancerStrategy = 'random',
        int $timeout = 5,
        int $tries = 2,
        int $connectTimeout = 1
    ) {
        $this->discovery = $discovery;
        $this->circuitBreaker = $circuitBreaker;
        $this->loadBalancerStrategy = $loadBalancerStrategy;
        $this->timeout = $timeout;
        $this->tries = $tries;
        $this->connectTimeout = $connectTimeout;
        $this->parser = new JsonParser();

        // 设置负载均衡策略
        $this->discovery->setLoadBalancerStrategy($loadBalancerStrategy);
    }

    /**
     * 调用远程服务（动态服务发现）
     *
     * @param Protocol $protocol RPC 协议对象
     * @return mixed 调用结果
     * @throws RpcResponseException
     */
    public function call(Protocol $protocol): mixed
    {
        $serviceName = $protocol->getInterface();

        // 1. 熔断器检查
        if (!$this->circuitBreaker->allowRequest($serviceName)) {
            throw new RpcResponseException(Error::make(
                -32000,
                "Circuit breaker is open for service: {$serviceName}"
            ));
        }

        // 2. 动态服务发现（每次调用都可能获取不同的实例）
        $instance = $this->discovery->discover($serviceName);

        if ($instance === null) {
            $this->circuitBreaker->recordFailure($serviceName);
            throw new RpcResponseException(Error::make(
                -32001,
                "No available instance for service: {$serviceName}"
            ));
        }

        // 3. 执行带重试的调用
        return $this->callWithRetry($instance, $serviceName, $protocol);
    }

    /**
     * 带重试的调用（失败时切换实例）
     *
     * @param ServiceInstanceInterface $initialInstance 初始实例
     * @param string $serviceName 服务名称
     * @param Protocol $protocol RPC 协议对象
     * @return mixed 调用结果
     * @throws RpcResponseException
     */
    protected function callWithRetry(
        ServiceInstanceInterface $initialInstance,
        string $serviceName,
        Protocol $protocol
    ): mixed {
        $currentInstance = $initialInstance;
        $lastException = null;

        for ($attempt = 0; $attempt < $this->tries; $attempt++) {
            try {
                // 执行调用
                $result = $this->doCall($currentInstance, $protocol);
                
                // 成功：记录并返回
                $this->circuitBreaker->recordSuccess($serviceName);
                return $result;
                
            } catch (Throwable $e) {
                $lastException = $e;
                $this->circuitBreaker->recordFailure($serviceName);

                // 如果还有重试机会
                if ($attempt < $this->tries - 1) {
                    // 指数退避
                    $backoff = min(100 * pow(2, $attempt), 1000); // 最多 1 秒
                    \Swoole\Coroutine::sleep($backoff / 1000);

                    // 🎯 关键：重新服务发现，可能切换到其他健康实例
                    $newInstance = $this->discovery->discover($serviceName);
                    
                    if ($newInstance !== null && $newInstance->getId() !== $currentInstance->getId()) {
                        $currentInstance = $newInstance;
                    } else {
                        // 没有其他实例可用，停止重试
                        break;
                    }
                }
            }
        }

        // 所有重试都失败
        throw new RpcResponseException(Error::make(
            -32099,
            "RPC call failed after {$this->tries} tries: " . ($lastException?->getMessage() ?? 'Unknown error')
        ));
    }

    /**
     * 执行实际的 RPC 调用
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param Protocol $protocol RPC 协议对象
     * @return mixed 调用结果
     * @throws Throwable
     */
    protected function doCall(ServiceInstanceInterface $instance, Protocol $protocol): mixed
    {
        // 获取连接（从连接池或新建）
        $client = $this->getConnection($instance);

        try {
            // 编码请求
            $data = $this->parser->encode($protocol);
            $packed = Packer::pack($data);

            // 发送请求
            if (!$client->send($packed)) {
                $this->closeConnection($instance);
                throw new \RuntimeException("Failed to send data to {$instance->getHost()}:{$instance->getPort()}");
            }

            // 接收响应
            $response = $this->receive($client);

            if ($response === false || $response === '') {
                $this->closeConnection($instance);
                throw new \RuntimeException("Failed to receive response from {$instance->getHost()}:{$instance->getPort()}");
            }

            // 解码响应
            $result = $this->parser->decodeResponse($response);

            // 检查是否为错误响应
            if ($result instanceof Error) {
                throw new RpcResponseException($result);
            }

            return $result;
            
        } catch (Throwable $e) {
            // 发生异常时关闭连接
            $this->closeConnection($instance);
            throw $e;
        }
    }

    /**
     * 获取连接（连接池管理）
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @return Client Swoole 协程客户端
     */
    protected function getConnection(ServiceInstanceInterface $instance): Client
    {
        $key = $instance->getId();

        // 尝试复用已有连接
        if (isset($this->connectionPool[$key])) {
            $client = $this->connectionPool[$key];
            
            if ($client->isConnected()) {
                return $client;
            }
            
            // 连接已断开，清理
            unset($this->connectionPool[$key]);
        }

        // 创建新连接
        $client = new Client(SWOOLE_SOCK_TCP);

        $client->set([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'open_eof_check' => true,
            'package_eof' => "\r\n",
        ]);

        if (!$client->connect($instance->getHost(), $instance->getPort(), $this->connectTimeout)) {
            $client->close();
            throw new \RuntimeException(
                "Failed to connect to {$instance->getHost()}:{$instance->getPort()}"
            );
        }

        // 存入连接池
        $this->connectionPool[$key] = $client;

        return $client;
    }

    /**
     * 关闭连接
     *
     * @param ServiceInstanceInterface $instance 服务实例
     */
    protected function closeConnection(ServiceInstanceInterface $instance): void
    {
        $key = $instance->getId();

        if (isset($this->connectionPool[$key])) {
            try {
                $this->connectionPool[$key]->close();
            } catch (Throwable $e) {
                // 忽略关闭错误
            }
            unset($this->connectionPool[$key]);
        }
    }

    /**
     * 接收数据（处理粘包）
     *
     * @param Client $client Swoole 客户端
     * @return string|false 解包后的数据
     */
    protected function receive(Client $client): string|false
    {
        $data = '';
        $startTime = microtime(true);

        while (true) {
            // 检查总超时
            if ((microtime(true) - $startTime) >= $this->timeout) {
                return $data ?: false;
            }

            $chunk = $client->recv(0.1); // 100ms 超时检查

            if ($chunk === false || $chunk === '') {
                return $data ?: false;
            }

            $data .= $chunk;

            // 尝试使用 Packer 解包
            try {
                $unpacked = Packer::unpack($data);
                if ($unpacked && isset($unpacked[1])) {
                    return $unpacked[1];
                }
            } catch (Throwable $e) {
                // 继续接收更多数据
            }
        }
    }

    /**
     * 设置负载均衡策略
     *
     * @param string $strategy 策略名称
     * @return self
     */
    public function setLoadBalancer(string $strategy): self
    {
        $this->loadBalancerStrategy = $strategy;
        $this->discovery->setLoadBalancerStrategy($strategy);
        return $this;
    }

    /**
     * 设置调用超时时间
     *
     * @param int $timeout 超时时间（秒）
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);
        return $this;
    }

    /**
     * 设置连接超时时间
     *
     * @param int $timeout 连接超时（秒）
     * @return self
     */
    public function setConnectTimeout(int $timeout): self
    {
        $this->connectTimeout = max(1, $timeout);
        return $this;
    }

    /**
     * 设置重试次数
     *
     * @param int $tries 重试次数
     * @return self
     */
    public function setTries(int $tries): self
    {
        $this->tries = max(1, min(5, $tries));
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
     * 获取连接池统计信息
     *
     * @return array
     */
    public function getPoolStats(): array
    {
        return [
            'total_connections' => count($this->connectionPool),
            'active_connections' => count(array_filter(
                $this->connectionPool,
                fn(Client $client) => $client->isConnected()
            )),
        ];
    }

    /**
     * 关闭所有连接
     */
    public function closeAllConnections(): void
    {
        foreach ($this->connectionPool as $key => $client) {
            try {
                $client->close();
            } catch (Throwable $e) {
                // 忽略关闭错误
            }
        }
        $this->connectionPool = [];
    }

    /**
     * 析构函数：确保资源释放
     */
    public function __destruct()
    {
        $this->closeAllConnections();
    }
}
