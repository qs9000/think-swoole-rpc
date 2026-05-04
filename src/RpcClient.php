<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\RpcClientInterface;
use qs9000\rpc\contract\ServiceInstanceInterface;
use think\swoole\rpc\JsonParser;
use think\swoole\rpc\Protocol;
use Throwable;

/**
 * HTTP RPC 客户端
 *
 * 用于非 Swoole 环境或调试场景，基于 HTTP 协议实现
 * 
 * 特点：
 * - 使用 cURL 进行 HTTP 通信
 * - 无连接池（短连接）
 * - 相同的协议编解码逻辑
 * - 便于跨语言调用和调试
 *
 * @package qs9000\rpc
 */
class RpcClient implements RpcClientInterface
{
    /** @var ServiceDiscovery 服务发现器 */
    protected ServiceDiscovery $discovery;

    /** @var CircuitBreaker 熔断器 */
    protected CircuitBreaker $circuitBreaker;

    /** @var string 负载均衡策略 */
    protected string $loadBalancer = 'random';

    /** @var int 调用超时时间（毫秒） */
    protected int $timeout = 5000;

    /** @var int 最大重试次数 */
    protected int $retryTimes = 1;

    /** @var JsonParser JSON 解析器（复用） */
    protected JsonParser $parser;

    public function __construct(
        ?ServiceDiscovery $discovery = null,
        ?CircuitBreaker $circuitBreaker = null
    ) {
        $this->discovery = $discovery ?? new ServiceDiscovery();
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker();
        $this->parser = new JsonParser();

        // 从配置加载参数
        if (function_exists('config')) {
            $this->loadBalancer = config('rpc.discovery.loadbalancer', 'random');
            $this->timeout = (int) config('rpc.timeout', 5000);
            $this->retryTimes = (int) config('rpc.tries', 1);
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

        // 3. 执行调用（带重试）
        return $this->callWithRetry($instance, $service, $method, $params, $version);
    }

    /**
     * 调用指定服务实例
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
            throw $this->wrapException($e, $service, $method);
        }
    }

    /**
     * 带重试的调用
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
                
                // 成功
                $this->circuitBreaker->recordSuccess($service);
                return $result;
                
            } catch (Throwable $e) {
                $lastException = $e;
                $this->circuitBreaker->recordFailure($service);

                // 如果还有重试机会
                if ($attempt < $this->retryTimes - 1) {
                    // 指数退避
                    usleep($this->calculateBackoff($attempt));
                    
                    // 重新发现服务实例
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
     * @throws RpcException
     */
    protected function executeCall(
        ServiceInstanceInterface $instance,
        string $service,
        string $method,
        array $params,
        ?string $version
    ): mixed {
        // 1. 构建接口名
        $interface = $version ? "{$service}.{$version}" : $service;

        // 2. 构建协议对象
        $protocol = Protocol::make($interface, $method, $params);

        // 3. 编码请求
        $requestData = $this->parser->encode($protocol);

        // 4. 发送 HTTP 请求
        $responseData = $this->httpRequest(
            $instance->getHost(),
            $instance->getPort(),
            '/rpc',
            $requestData
        );

        if ($responseData === false) {
            throw new RpcException(
                sprintf(
                    'HTTP request failed to %s:%d/rpc',
                    $instance->getHost(),
                    $instance->getPort()
                ),
                -32099
            );
        }

        // 5. 解码响应
        $result = $this->parser->decodeResponse($responseData);

        // 6. 检查错误
        if ($result instanceof \think\swoole\rpc\Error) {
            throw new RpcException(
                $result->getMessage(),
                $result->getCode(),
                null,
                $result->getData()
            );
        }

        return $result;
    }

    /**
     * 发送 HTTP 请求
     *
     * @param string $host 主机地址
     * @param int $port 端口
     * @param string $path 路径
     * @param string $body 请求体
     * @return string|false 响应数据
     */
    protected function httpRequest(string $host, int $port, string $path, string $body): string|false
    {
        $url = sprintf('http://%s:%d%s', $host, $port, $path);

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeout,
            CURLOPT_CONNECTTIMEOUT_MS => min(1000, $this->timeout),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
                'Accept: application/json',
            ],
            CURLOPT_FAILONERROR => false, // 不将 HTTP 错误码视为失败
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        
        curl_close($ch);

        // cURL 错误
        if ($response === false || $errno !== 0) {
            return false;
        }

        // HTTP 错误码
        if ($httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        return $response;
    }

    /**
     * 计算退避时间（微秒）
     *
     * @param int $attempt 重试次数
     * @return int 退避时间
     */
    protected function calculateBackoff(int $attempt): int
    {
        $base = 100_000;    // 100ms
        $max = 1_000_000;   // 1s
        return (int) min($base * pow(2, $attempt), $max);
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
     * 设置超时时间
     *
     * @param int $timeout 超时时间（毫秒）
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(100, $timeout);
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
        $this->retryTimes = max(1, min(5, $times));
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
     * 关闭客户端（HTTP 无需特殊处理）
     */
    public function close(): void
    {
        // HTTP 短连接，无需清理
    }
}
