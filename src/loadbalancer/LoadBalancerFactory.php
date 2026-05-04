<?php

declare(strict_types=1);

namespace qs9000\rpc\loadbalancer;

use think\facade\Config;

/**
 * 负载均衡器工厂
 */
class LoadBalancerFactory
{
    protected array $strategies = [];

    public function __construct()
    {
        if (function_exists('config')) {
            $strategies = config('rpc.strategies', []);
            foreach ($strategies as $name => $class) {
                $this->strategies[$name] = $class;
            }
        }
    }

    /**
     * 创建负载均衡器
     */
    public function create(string $strategy = 'random'): LoadBalancerInterface
    {
        $strategy = strtolower($strategy);

        if (!isset($this->strategies[$strategy])) {
            return new RandomLoadBalancer();
        }

        $class = $this->strategies[$strategy];
        
        return new $class();
    }

    /**
     * 注册策略
     */
    public function register(string $name, string $class): void
    {
        $this->strategies[$name] = $class;
    }

    /**
     * 获取所有策略
     */
    public function getStrategies(): array
    {
        return array_keys($this->strategies);
    }

    /**
     * 获取所有可用的策略名称
     */
    public function getAvailableStrategies(): array
    {
        $strategies = array_keys($this->strategies);
        if (empty($strategies)) {
            // 默认策略
            $strategies = ['random', 'roundrobin', 'weight', 'leastconnection', 'consistenthash'];
        }
        return $strategies;
    }
}
