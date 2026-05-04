<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 随机负载均衡器
 */
class RandomLoadBalancer implements LoadBalancerInterface
{
    public function select(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        $index = array_rand($instances);
        return $instances[$index];
    }
}
