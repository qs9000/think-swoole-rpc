<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 一致性哈希负载均衡器
 */
class ConsistentHashLoadBalancer implements LoadBalancerInterface
{
    protected array $ring = [];
    protected array $sortedKeys = [];
    protected int $virtualNodes = 100;

    public function __construct(int $virtualNodes = 100)
    {
        $this->virtualNodes = $virtualNodes;
    }

    public function select(array $instances, ?string $key = null): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        // 重建哈希环
        $this->buildRing($instances);

        // 使用传入的 key 或客户端 IP
        $clientKey = $key ?? $this->getClientKey();
        $hash = $this->hash($clientKey);

        // 找到第一个大于等于 hash 的节点
        foreach ($this->sortedKeys as $key) {
            if ($hash <= $key) {
                return $this->ring[$key];
            }
        }

        // 环绕到第一个节点
        return $this->ring[$this->sortedKeys[0]] ?? null;
    }

    protected function buildRing(array $instances): void
    {
        $this->ring = [];
        $this->sortedKeys = [];

        foreach ($instances as $instance) {
            $instanceId = $instance->getId();

            // 为每个真实节点创建虚拟节点
            for ($i = 0; $i < $this->virtualNodes; $i++) {
                $nodeKey = $this->hash("{$instanceId}:{$i}");
                $this->ring[$nodeKey] = $instance;
            }
        }

        // 排序键
        ksort($this->ring);
        $this->sortedKeys = array_keys($this->ring);
    }

    protected function hash(string $key): int
    {
        // 使用 CRC32 或其他哈希算法
        return abs(crc32($key));
    }

    protected function getClientKey(): string
    {
        // 优先使用客户端 IP
        if (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        // 使用随机值
        return uniqid();
    }
}
