<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 最少连接负载均衡器
 */
class LeastConnectionLoadBalancer implements LoadBalancerInterface
{
    protected static array $connections = [];

    public function select(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        $selected = null;
        $minConnections = PHP_INT_MAX;

        foreach ($instances as $instance) {
            $id = $instance->getId();
            $connections = self::$connections[$id] ?? 0;

            if ($connections < $minConnections) {
                $minConnections = $connections;
                $selected = $instance;
            }
        }

        if ($selected) {
            $this->incrementConnection($selected->getId());
        }

        return $selected;
    }

    public function incrementConnection(string $instanceId): void
    {
        self::$connections[$instanceId] = (self::$connections[$instanceId] ?? 0) + 1;
    }

    public function decrementConnection(string $instanceId): void
    {
        if (isset(self::$connections[$instanceId]) && self::$connections[$instanceId] > 0) {
            self::$connections[$instanceId]--;
        }
    }

    public function getConnections(string $instanceId): int
    {
        return self::$connections[$instanceId] ?? 0;
    }

    public function reset(): void
    {
        self::$connections = [];
    }
}
