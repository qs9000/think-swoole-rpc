<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\ServiceInstanceInterface;
use qs9000\rpc\loadbalancer\LoadBalancerFactory;
use qs9000\rpc\loadbalancer\LoadBalancerInterface;

/**
 * 服务发现器
 *
 * 负责从注册中心获取可用服务实例，并提供：
 * - 本地缓存（减少注册中心压力）
 * - 健康检查过滤
 * - 负载均衡选择
 * - 多策略支持
 *
 * @package qs9000\rpc
 */
class ServiceDiscovery
{
    /** @var RegistryClient 注册中心客户端 */
    protected RegistryClient $registryClient;

    /** @var array 本地缓存 [serviceName => ['timestamp' => int, 'instances' => ServiceInstance[]]] */
    protected array $localCache = [];

    /** @var int 缓存 TTL（秒） */
    protected int $cacheTtl = 30;

    /** @var string 负载均衡策略 */
    protected string $loadBalancerStrategy = 'random';

    /** @var LoadBalancerInterface|null 负载均衡器实例（缓存） */
    protected ?LoadBalancerInterface $loadBalancer = null;

    /** @var LoadBalancerFactory 负载均衡器工厂 */
    protected LoadBalancerFactory $loadBalancerFactory;

    public function __construct(?RegistryClient $registryClient = null)
    {
        $this->registryClient = $registryClient ?? new RegistryClient();
        $this->loadBalancerFactory = new LoadBalancerFactory();
        
        // 从配置加载默认值
        if (function_exists('config')) {
            $discoveryConfig = config('rpc.discovery', []);
            $this->loadBalancerStrategy = $discoveryConfig['loadbalancer'] ?? 'random';
            $this->cacheTtl = (int) ($discoveryConfig['cache_ttl'] ?? 30);
        }
    }

    /**
     * 发现服务 - 获取单个可用实例（通过负载均衡选择）
     *
     * @param string $serviceName 服务名称
     * @return ServiceInstanceInterface|null
     */
    public function discover(string $serviceName): ?ServiceInstanceInterface
    {
        $instances = $this->getHealthyInstances($serviceName);

        if (empty($instances)) {
            return null;
        }

        return $this->selectInstance($instances);
    }

    /**
     * 获取健康的服务实例列表
     *
     * @param string $serviceName 服务名称
     * @return ServiceInstanceInterface[]
     */
    public function getHealthyInstances(string $serviceName): array
    {
        $instances = $this->getInstances($serviceName);

        return array_values(array_filter(
            $instances,
            fn(ServiceInstanceInterface $instance) => $instance->isHealthy()
        ));
    }

    /**
     * 获取所有服务实例列表（包含不健康的）
     *
     * @param string $serviceName 服务名称
     * @return ServiceInstanceInterface[]
     */
    public function getInstances(string $serviceName): array
    {
        // 1. 尝试从缓存获取
        if ($this->hasValidCache($serviceName)) {
            return $this->getCachedInstances($serviceName);
        }

        // 2. 从注册中心获取
        try {
            $instances = $this->fetchInstancesFromRegistry($serviceName);
            
            if (!empty($instances)) {
                $this->setCache($serviceName, $instances);
                return $instances;
            }
        } catch (\Throwable $e) {
            // 记录错误但不中断服务
            $message = sprintf(
                '[ServiceDiscovery] Failed to fetch instances for %s: %s',
                $serviceName,
                $e->getMessage()
            );
            
            // 使用 ThinkPHP Log facade（如果可用），否则降级到 error_log
            if (class_exists('\think\facade\Log')) {
                \think\facade\Log::warning($message);
            } else {
                error_log($message);
            }
        }

        // 3. 网络失败时，使用过期缓存（降级策略）
        $cachedInstances = $this->getCachedInstances($serviceName);
        if (!empty($cachedInstances)) {
            return $cachedInstances;
        }

        return [];
    }

    /**
     * 获取服务列表
     *
     * @return array
     */
    public function getServices(): array
    {
        try {
            $response = $this->registryClient->getServices();
            return ($response['success'] ?? false) ? ($response['data'] ?? []) : [];
        } catch (\Throwable $e) {
            $message = '[ServiceDiscovery] Failed to get services: ' . $e->getMessage();
            
            // 使用 ThinkPHP Log facade（如果可用），否则降级到 error_log
            if (class_exists('\think\facade\Log')) {
                \think\facade\Log::warning($message);
            } else {
                error_log($message);
            }
            
            return [];
        }
    }

    /**
     * 获取服务详情
     *
     * @param string $serviceName 服务名称
     * @return array
     */
    public function getService(string $serviceName): array
    {
        try {
            $response = $this->registryClient->getService($serviceName);
            return ($response['success'] ?? false) ? ($response['data'] ?? []) : [];
        } catch (\Throwable $e) {
            $message = sprintf(
                '[ServiceDiscovery] Failed to get service %s: %s',
                $serviceName,
                $e->getMessage()
            );
            
            // 使用 ThinkPHP Log facade（如果可用），否则降级到 error_log
            if (class_exists('\think\facade\Log')) {
                \think\facade\Log::warning($message);
            } else {
                error_log($message);
            }
            
            return [];
        }
    }

    /**
     * 设置负载均衡策略
     *
     * @param string $strategy 策略名称
     * @return self
     */
    public function setLoadBalancerStrategy(string $strategy): self
    {
        if ($this->loadBalancerStrategy !== $strategy) {
            $this->loadBalancerStrategy = $strategy;
            $this->loadBalancer = null; // 重置负载均衡器
        }
        return $this;
    }

    /**
     * 设置缓存 TTL（秒）
     *
     * @param int $ttl 缓存时间
     * @return self
     */
    public function setCacheTtl(int $ttl): self
    {
        $this->cacheTtl = max(1, $ttl); // 最小 1 秒
        return $this;
    }

    /**
     * 清除指定服务的缓存
     *
     * @param string $serviceName 服务名称
     */
    public function clearCache(string $serviceName): void
    {
        unset($this->localCache[$serviceName]);
    }

    /**
     * 清除所有缓存
     */
    public function clearAllCache(): void
    {
        $this->localCache = [];
    }

    /**
     * 获取注册客户端
     *
     * @return RegistryClient
     */
    public function getRegistryClient(): RegistryClient
    {
        return $this->registryClient;
    }

    /**
     * 从注册中心获取实例列表
     *
     * @param string $serviceName 服务名称
     * @return ServiceInstanceInterface[]
     */
    protected function fetchInstancesFromRegistry(string $serviceName): array
    {
        $instances = [];

        // 优先使用实例列表接口
        $response = $this->registryClient->getInstances($serviceName);

        if (($response['success'] ?? false) && !empty($response['data']['instances'])) {
            foreach ($response['data']['instances'] as $item) {
                $instances[] = new ServiceInstance($item);
            }
            return $instances;
        }

        // 兼容单个实例接口（旧版 API）
        $response = $this->registryClient->discover($serviceName);

        if (($response['success'] ?? false) && !empty($response['data'])) {
            if (isset($response['data']['id']) || isset($response['data']['host'])) {
                $instances[] = new ServiceInstance($response['data']);
            }
        }

        return $instances;
    }

    /**
     * 使用负载均衡选择实例
     *
     * @param ServiceInstanceInterface[] $instances 实例列表
     * @return ServiceInstanceInterface|null
     */
    protected function selectInstance(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }

        // 单实例直接返回
        if (count($instances) === 1) {
            return $instances[0];
        }

        // 获取或创建负载均衡器
        if ($this->loadBalancer === null) {
            $this->loadBalancer = $this->loadBalancerFactory->create($this->loadBalancerStrategy);
        }

        return $this->loadBalancer->select($instances);
    }

    /**
     * 检查缓存是否存在且有效
     *
     * @param string $serviceName 服务名称
     * @return bool
     */
    protected function hasValidCache(string $serviceName): bool
    {
        if (!isset($this->localCache[$serviceName])) {
            return false;
        }

        $cache = $this->localCache[$serviceName];
        $age = time() - $cache['timestamp'];

        return $age < $this->cacheTtl;
    }

    /**
     * 获取缓存的实例列表
     *
     * @param string $serviceName 服务名称
     * @return ServiceInstanceInterface[]
     */
    protected function getCachedInstances(string $serviceName): array
    {
        return $this->localCache[$serviceName]['instances'] ?? [];
    }

    /**
     * 设置缓存
     *
     * @param string $serviceName 服务名称
     * @param ServiceInstanceInterface[] $instances 实例列表
     */
    protected function setCache(string $serviceName, array $instances): void
    {
        $this->localCache[$serviceName] = [
            'timestamp' => time(),
            'instances' => $instances,
        ];
    }

    /**
     * 获取缓存统计信息
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        $stats = [
            'total_cached_services' => count($this->localCache),
            'services' => [],
        ];

        foreach ($this->localCache as $serviceName => $cache) {
            $age = time() - $cache['timestamp'];
            $stats['services'][$serviceName] = [
                'instance_count' => count($cache['instances']),
                'cache_age' => $age,
                'is_valid' => $age < $this->cacheTtl,
            ];
        }

        return $stats;
    }
}
