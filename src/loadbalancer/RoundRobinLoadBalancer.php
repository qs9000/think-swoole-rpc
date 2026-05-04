<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 轮询负载均衡器
 */
class RoundRobinLoadBalancer implements LoadBalancerInterface
{
    protected static array $counters = [];

    public function select(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        // 生成实例的唯一键
        $key = $this->generateKey($instances);

        if (!isset(self::$counters[$key])) {
            self::$counters[$key] = 0;
        }

        $index = self::$counters[$key] % count($instances);
        self::$counters[$key]++;

        return $instances[$index];
    }

    protected function generateKey(array $instances): string
    {
        $ids = array_map(fn($i) => $i->getId(), $instances);
        sort($ids);
        return implode(',', $ids);
    }
}
