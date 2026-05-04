<?php

declare(strict_types=1);

namespace qs9000\rpc\contract;

/**
 * RPC 客户端接口
 */
interface RpcClientInterface
{
    /**
     * 调用远程服务
     *
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     */
    public function call(string $service, string $method, array $params = [], ?string $version = null): mixed;

    /**
     * 调用指定服务实例
     *
     * @param ServiceInstanceInterface $instance 服务实例
     * @param string $service 服务名称
     * @param string $method 方法名
     * @param array $params 参数
     * @param string|null $version 版本号
     * @return mixed
     */
    public function callInstance(
        ServiceInstanceInterface $instance,
        string $service,
        string $method,
        array $params = [],
        ?string $version = null
    ): mixed;

    /**
     * 设置超时时间
     */
    public function setTimeout(int $timeout): self;

    /**
     * 设置负载均衡策略
     */
    public function setLoadBalancer(string $strategy): self;

    /**
     * 设置重试次数
     */
    public function setRetryTimes(int $times): self;

    /**
     * 关闭客户端
     */
    public function close(): void;
}
