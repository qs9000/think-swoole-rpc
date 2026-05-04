<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\RpcClientInterface;

/**
 * RPC 客户端 Trait
 *
 * 提供简洁的 RPC 调用方式，可在任何类中使用
 * 
 * 使用示例：
 * ```php
 * class UserService
 * {
 *     use RpcClientTrait;
 *
 *     // 必须定义服务名称
 *     protected string $serviceName = 'user';
 *     
 *     // 可选：指定协议类型（默认 tcp）
 *     protected string $protocol = 'tcp';
 *
 *     public function getUser(int $id): array
 *     {
 *         return $this->call('getUser', ['id' => $id]);
 *     }
 *
 *     public function createUser(array $data): array
 *     {
 *         return $this->call('createUser', $data);
 *     }
 * }
 *
 * // 使用
 * $service = new UserService();
 * $user = $service->getUser(1);
 * ```
 *
 * @package qs9000\rpc
 */
trait RpcClientTrait
{
    /** @var string 服务名称（子类必须定义） */
    protected string $serviceName = '';

    /** @var string 负载均衡策略 */
    protected string $loadBalancer = 'random';

    /** @var int 调用超时时间（毫秒） */
    protected int $timeout = 5000;

    /** @var string|null 服务版本号 */
    protected ?string $version = null;

    /** @var string 协议类型: tcp | http */
    protected string $protocol = 'tcp';

    /** @var int 最大重试次数 */
    protected int $retryTimes = 2;

    /** @var RpcClientInterface|null RPC 客户端实例（懒加载） */
    protected ?RpcClientInterface $rpcClient = null;

    /**
     * 调用远程服务方法
     *
     * @param string $method 方法名
     * @param array $params 参数
     * @return mixed
     * @throws RpcException
     */
    protected function call(string $method, array $params = []): mixed
    {
        if (empty($this->serviceName)) {
            throw new \RuntimeException(
                'Service name is not defined. Please set $serviceName property in your class.'
            );
        }

        return $this->getRpcClient()->call(
            $this->serviceName,
            $method,
            $params,
            $this->version
        );
    }

    /**
     * 调用远程服务（指定版本）
     *
     * @param string $version 版本号
     * @param string $method 方法名
     * @param array $params 参数
     * @return mixed
     * @throws RpcException
     */
    protected function callWithVersion(string $version, string $method, array $params = []): mixed
    {
        return $this->getRpcClient()->call(
            $this->serviceName,
            $method,
            $params,
            $version
        );
    }

    /**
     * 批量调用多个方法
     *
     * @param array $calls 调用列表 ['key' => ['method', ['param1' => val1]]]
     * @return array 结果数组
     *
     * 示例：
     * ```php
     * $results = $this->multiCall([
     *     'user' => ['getUser', ['id' => 1]],
     *     'order' => ['getOrder', ['id' => 100]],
     * ]);
     * ```
     */
    protected function multiCall(array $calls): array
    {
        $results = [];

        foreach ($calls as $key => $call) {
            if (!is_array($call) || count($call) < 2) {
                $results[$key] = [
                    'error' => true,
                    'code' => -32602,
                    'message' => 'Invalid call format',
                ];
                continue;
            }

            [$method, $params] = $call;

            try {
                $results[$key] = $this->call($method, $params);
            } catch (RpcException $e) {
                $results[$key] = [
                    'error' => true,
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * 获取 RPC 客户端实例（懒加载）
     *
     * @return RpcClientInterface
     */
    protected function getRpcClient(): RpcClientInterface
    {
        if ($this->rpcClient === null) {
            // 根据协议类型选择客户端
            $clientClass = $this->protocol === 'tcp'
                ? SwooleRpcClient::class
                : RpcClient::class;

            // 尝试从容器获取，否则直接实例化
            $this->rpcClient = function_exists('app')
                ? app($clientClass)
                : new $clientClass();

            // 应用配置
            $this->rpcClient
                ->setTimeout($this->timeout)
                ->setLoadBalancer($this->loadBalancer)
                ->setRetryTimes($this->retryTimes);
        }

        return $this->rpcClient;
    }

    /**
     * 设置服务名称
     *
     * @param string $name 服务名称
     * @return static
     */
    public function setServiceName(string $name): static
    {
        $this->serviceName = $name;
        $this->rpcClient = null; // 重置客户端
        return $this;
    }

    /**
     * 设置调用超时时间
     *
     * @param int $timeout 超时时间（毫秒）
     * @return static
     */
    public function setTimeout(int $timeout): static
    {
        $this->timeout = max(100, $timeout);
        $this->rpcClient = null; // 重置客户端以应用新配置
        return $this;
    }

    /**
     * 设置负载均衡策略
     *
     * @param string $strategy 策略名称
     * @return static
     */
    public function setLoadBalancer(string $strategy): static
    {
        $this->loadBalancer = $strategy;
        $this->rpcClient = null; // 重置客户端
        return $this;
    }

    /**
     * 设置服务版本号
     *
     * @param string|null $version 版本号
     * @return static
     */
    public function setVersion(?string $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * 设置重试次数
     *
     * @param int $times 重试次数（1-5）
     * @return static
     */
    public function setRetryTimes(int $times): static
    {
        $this->retryTimes = max(1, min(5, $times));
        $this->rpcClient = null; // 重置客户端
        return $this;
    }

    /**
     * 设置协议类型
     *
     * @param string $protocol tcp | http
     * @return static
     */
    public function setProtocol(string $protocol): static
    {
        $validProtocols = ['tcp', 'http'];
        
        if (!in_array($protocol, $validProtocols, true)) {
            throw new \InvalidArgumentException(
                "Invalid protocol: {$protocol}. Must be one of: " . implode(', ', $validProtocols)
            );
        }

        $this->protocol = $protocol;
        $this->rpcClient = null; // 重置客户端实例
        return $this;
    }

    /**
     * 使用 TCP 协议（高性能，推荐生产环境使用）
     *
     * @return static
     */
    public function useTcp(): static
    {
        return $this->setProtocol('tcp');
    }

    /**
     * 使用 HTTP 协议（便于调试和跨语言调用）
     *
     * @return static
     */
    public function useHttp(): static
    {
        return $this->setProtocol('http');
    }

    /**
     * 获取当前配置信息
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'service_name' => $this->serviceName,
            'protocol' => $this->protocol,
            'timeout' => $this->timeout,
            'load_balancer' => $this->loadBalancer,
            'version' => $this->version,
            'retry_times' => $this->retryTimes,
        ];
    }
}
