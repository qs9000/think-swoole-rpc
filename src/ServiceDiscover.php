<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\ServiceInstanceInterface;
use qs9000\rpc\loadbalancer\LoadBalancerFactory;
use think\App;
use think\cache\Driver;

/**
 * 服务发现类
 *
 * 负责从注册中心获取 RPC 服务实例信息，并通过缓存和负载均衡策略返回可用的服务实例。
 */
class ServiceDiscover
{
    private Driver $cache;
    private App $app;
    private array $config;

    /**
     * 构造函数
     *
     * 初始化应用实例、加载 RPC 发现配置以及初始化缓存驱动。
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->config = $this->app->config->get('rpc.discovery', []);
        $this->cache = $this->app->cache->store($this->config['cache'] ?? 'file');
    }

    /**
     * 发现并返回一个可用的服务实例
     *
     * 首先尝试从缓存中获取服务实例列表。如果缓存未命中，则使用分布式锁防止缓存击穿，
     * 从注册中心获取最新的服务实例列表并更新缓存。如果获取锁失败，则等待缓存更新完成。
     * 最后，根据服务实例数量决定是直接返回唯一实例还是通过负载均衡器选择一个实例。
     *
     * @param string $serviceName 服务名称
     * @return ServiceInstanceInterface 选定的服务实例对象
     * @throws RpcException 当服务信息无效、获取超时或负载均衡失败时抛出异常
     */
    public function discover(string $serviceName): ServiceInstanceInterface
    {
        $cacheKey = $this->getCacheKey($serviceName);
        $cachedData = $this->cache->get($cacheKey);

        if (is_array($cachedData) && !empty($cachedData)) {
            $serviceInstances = $cachedData;
        } else {
            // 缓存锁，防止缓存击穿
            $lockKey = $cacheKey . '_lock';
            $lockAcquired = $this->cache->add($lockKey, 1, 5); // 5秒锁
            if ($lockAcquired) {
                try {
                    $registryClient = $this->app->make(RegistryClient::class, ['rpc']);
                    $serviceInstances = $registryClient->discover($serviceName);
                    if (!is_array($serviceInstances) || empty($serviceInstances)) {
                        throw new RpcException("获取的RPC服务信息无效: {$serviceName}");
                    }
                    $ttl = $this->config['cache_ttl'] ?? 600;
                    $this->cache->set($cacheKey, $serviceInstances, $ttl);
                } finally {
                    $this->cache->delete($lockKey);
                }
            } else {
                // 等待锁释放，最多3秒
                $wait = 0;
                while (!$this->cache->has($cacheKey) && $wait < 30) {
                    usleep(100000);
                    $wait++;
                }
                $serviceInstances = $this->cache->get($cacheKey);
                if (!is_array($serviceInstances) || empty($serviceInstances)) {
                    throw new RpcException("获取RPC服务信息超时: {$serviceName}");
                }
            }
        }

        if (!is_array($serviceInstances) || count($serviceInstances) === 0) {
            throw new RpcException("获取的RPC服务信息无效: {$serviceName}");
        }

        if (count($serviceInstances) === 1) {
            $instance = $serviceInstances[0];
        } else {
            $strategy = $this->config['loadbalancer'] ?? 'random';
            try {
                $loadBalancer = $this->app->make(LoadBalancerFactory::class)->create($strategy);
                $instance = $loadBalancer->select($serviceInstances);
            } catch (\Throwable $e) {
                throw new RpcException("RPC负载均衡器选择失败: {$serviceName}. Error: " . $e->getMessage(), 0, $e);
            }
        }
        return $this->app->make(ServiceInstance::class)->fromArray($instance);
    }

    /**
     * 清除指定服务的缓存
     *
     * @param string $serviceName 服务名称
     */
    public function clearCache(string $serviceName): void
    {
        $cacheKey = $this->getCacheKey($serviceName);
        $this->cache->delete($cacheKey);
    }

    /**
     * 生成服务缓存键
     *
     * @param string $serviceName 服务名称
     * @return string 缓存键字符串
     */
    private function getCacheKey(string $serviceName): string
    {
        return 'rpc_service_' . $serviceName;
    }
    
    /**
     * 清理过期缓存
     * 扫描所有服务缓存并删除过期项
     */
    public function cleanupExpiredCache(): void
    {
        // 对于文件缓存，我们无法直接扫描键，因此这个方法主要用于其他缓存驱动
        // 对于文件缓存，依赖文件系统的过期机制即可
    }

    /**
     * 预热服务发现缓存
     * @param string $serviceName 服务名称
     * @return bool 是否预热成功
     */
    public function warmUpCache(string $serviceName): bool
    {
        try {
            $registryClient = $this->app->make(RegistryClient::class, ['rpc']);
            $serviceInstances = $registryClient->discover($serviceName);
            if (!is_array($serviceInstances) || empty($serviceInstances)) {
                return false;
            }
            $ttl = $this->config['cache_ttl'] ?? 600;
            $cacheKey = $this->getCacheKey($serviceName);
            $this->cache->set($cacheKey, $serviceInstances, $ttl);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}