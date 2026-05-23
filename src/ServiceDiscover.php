<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\ServiceInstanceInterface;
use qs9000\rpc\loadbalancer\LoadBalancerFactory;
use qs9000\rpc\RegistryClient;
use think\facade\Config;
use think\cache\Driver;
use think\facade\Log;

/**
 * 服务发现类
 *
 * 负责从注册中心获取 RPC 服务实例信息，并通过缓存和负载均衡策略返回可用的服务实例。
 */
class ServiceDiscover
{
    private array $config;
    private RegistryClient $registryClient;
    /**
     * 构造函数
     *
     * 初始化应用实例、加载 RPC 发现配置以及初始化缓存驱动。
     */
    public function __construct()
    {
        $registryClass = Config::get('rpc.registry.registry_class');
        $this->registryClient = app()->make($registryClass ?: RegistryClient::class,['rpc']);
        $this->config = Config::get('rpc.discovery');
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
        $services = $this->registryClient->discover($serviceName);
        if (!is_array($services) || count($services) === 0) {
            throw new RpcException('未从注册中心获取到RPC服务实例：' . $serviceName);
        }
        
        if (count($services) === 1) {
            $instance = $services[0];
        } else {
            $strategy = $this->config['loadbalancer'] ?? 'random';
            try {
                $loadBalancer = app()->make(LoadBalancerFactory::class)->create($strategy);
                $instance = $loadBalancer->select($services);
            } catch (\Throwable $e) {
                throw new RpcException("RPC负载均衡器选择失败: {$serviceName}. Error: " . $e->getMessage(), 0, $e);
            }
        }
        return app()->make(ServiceInstance::class)->fromArray($instance);
    }
}
