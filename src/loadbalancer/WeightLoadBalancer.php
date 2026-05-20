<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 平滑加权轮询负载均衡器（Nginx 算法）
 */
class WeightLoadBalancer implements LoadBalancerInterface
{
    protected static array $currentWeights = [];

    /**
     * 根据平滑加权轮询算法选择一个服务实例
     *
     * @param array $instances 可用的服务实例列表，需实现 ServiceInstanceInterface 接口
     * @return ServiceInstanceInterface|null 选中的服务实例，如果实例列表为空则返回 null
     */
    public function select(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        $key = $this->generateKey($instances);
        $totalWeight = 0;
        $best = null;

        // 初始化当前权重数组，确保每个实例组有独立的权重状态
        if (!isset(self::$currentWeights[$key])) {
            self::$currentWeights[$key] = [];
        }

        foreach ($instances as $instance) {
            $id = $instance->getId();
            $weight = $instance->getWeight();
            $totalWeight += $weight;

            // 初始化单个实例的当前权重，并累加其配置权重
            if (!isset(self::$currentWeights[$key][$id])) {
                self::$currentWeights[$key][$id] = 0;
            }
            self::$currentWeights[$key][$id] += $weight;

            // 选择当前权重最高的实例作为最佳候选
            if ($best === null || self::$currentWeights[$key][$id] > self::$currentWeights[$key][$best->getId()]) {
                $best = $instance;
            }
        }

        // 对选中实例的当前权重减去总权重，以实现平滑调度
        if ($best !== null) {
            $bestId = $best->getId();
            self::$currentWeights[$key][$bestId] -= $totalWeight;
        }

        return $best ?? $instances[array_rand($instances)];
    }

    /**
     * 生成基于实例 ID 的唯一键值，用于区分不同的实例组合
     *
     * @param array $instances 服务实例列表
     * @return string 排序后拼接的实例 ID 字符串
     */
    protected function generateKey(array $instances): string
    {
        $ids = array_map(fn($i) => $i->getId(), $instances);
        sort($ids);
        return implode(',', $ids);
    }
}