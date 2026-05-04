<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 负载均衡器接口
 */
interface LoadBalancerInterface
{
    /**
     * 选择一个实例
     *
     * @param ServiceInstanceInterface[] $instances
     */
    public function select(array $instances): ?ServiceInstanceInterface;
}
