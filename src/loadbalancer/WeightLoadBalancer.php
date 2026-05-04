<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 加权随机负载均衡器
 */
class WeightLoadBalancer implements LoadBalancerInterface
{
    public function select(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        // 计算总权重
        $totalWeight = 0;
        $weightedInstances = [];

        foreach ($instances as $instance) {
            $weight = $instance->getWeight();
            $totalWeight += $weight;

            // 每个实例根据权重重复添加
            for ($i = 0; $i < $weight; $i++) {
                $weightedInstances[] = $instance;
            }
        }

        if (empty($weightedInstances)) {
            return $instances[array_rand($instances)];
        }

        return $weightedInstances[array_rand($weightedInstances)];
    }
}
