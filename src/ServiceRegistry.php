<?php

declare(strict_types=1);

namespace qs9000\rpc;

/**
 * 服务注册器
 * 
 * 负责在 RPC 服务启动时向注册中心注册服务
 */
class ServiceRegistry
{
    protected RegistryClient $registryClient;
    protected ?ServiceInstance $instance = null;
    protected bool $registered = false;
    protected int $heartbeatInterval = 30;

    public function __construct(?RegistryClient $registryClient = null)
    {
        $this->registryClient = $registryClient ?? new RegistryClient();
    }

    /**
     * 注册服务到注册中心
     */
    public function register(): bool
    {
        if ($this->registered) {
            return true;
        }

        $serviceData = $this->buildServiceData();
        
        $response = $this->registryClient->register($serviceData);
        
        if ($response['success'] ?? false) {
            $this->registered = true;
            
            // 保存注册后的实例信息
            if (isset($response['data']['instance'])) {
                $this->instance = new ServiceInstance($response['data']['instance']);
            } else {
                $this->instance = new ServiceInstance($serviceData);
            }
            
            return true;
        }

        return false;
    }

    /**
     * 注销服务
     */
    public function deregister(): bool
    {
        if (!$this->registered && $this->instance === null) {
            // 未注册时也允许注销
            return true;
        }

        $serviceData = $this->buildServiceData();
        
        $response = $this->registryClient->deregister($serviceData);
        
        $this->registered = false;
        
        return ($response['success'] ?? false);
    }

    /**
     * 发送心跳
     */
    public function heartbeat(): bool
    {
        if ($this->instance === null) {
            return false;
        }

        $data = [
            'id' => $this->instance->getId(),
            'name' => $this->instance->getName(),
            'host' => $this->instance->getHost(),
            'port' => $this->instance->getPort(),
        ];

        $response = $this->registryClient->heartbeat($data);
        
        if ($response['success'] ?? false) {
            $this->instance->setHealthy(true);
            return true;
        }

        return false;
    }

    /**
     * 启动心跳定时器
     * 
     * 在 Swoole 环境中调用此方法会启动心跳定时器
     */
    public function startHeartbeat(): void
    {
        if (!class_exists('\Swoole\Timer')) {
            return;
        }

        \Swoole\Timer::tick($this->heartbeatInterval * 1000, function () {
            $this->heartbeat();
        });
    }

    /**
     * 设置心跳间隔（秒）
     */
    public function setHeartbeatInterval(int $seconds): self
    {
        $this->heartbeatInterval = $seconds;
        return $this;
    }

    /**
     * 检查是否已注册
     */
    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * 获取当前实例信息
     */
    public function getInstance(): ?ServiceInstance
    {
        return $this->instance;
    }

    /**
     * 获取注册客户端
     */
    public function getRegistryClient(): RegistryClient
    {
        return $this->registryClient;
    }

    /**
     * 构建服务注册数据
     */
    protected function buildServiceData(): array
    {
        $config = [];
        if (function_exists('config')) {
            $config = config('rpc.service', []);
        }
        
        return [
            'name' => $config['name'] ?? $this->detectServiceName(),
            'host' => $config['host'] ?? $this->detectHost(),
            'port' => (int) ($config['port'] ?? $this->detectPort()),
            'weight' => (int) ($config['weight'] ?? 100),
            'metadata' => $config['metadata'] ?? [
                'app_name' => function_exists('config') ? config('app_name', 'app') : 'app',
                'version' => function_exists('config') ? config('version', '1.0') : '1.0',
            ],
        ];
    }

    /**
     * 检测服务名称
     */
    protected function detectServiceName(): string
    {
        if (function_exists('config')) {
            return config('app_name', 'app');
        }
        return 'app';
    }

    /**
     * 检测主机地址
     */
    protected function detectHost(): string
    {
        // 优先从配置获取
        if (function_exists('config')) {
            $host = config('rpc.service.host');
            if ($host) {
                return $host;
            }
        }

        // 尝试自动检测
        if (isset($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }

        return '127.0.0.1';
    }

    /**
     * 检测端口
     */
    protected function detectPort(): int
    {
        // 优先从配置获取
        if (function_exists('config')) {
            $port = config('rpc.service.port');
            if ($port) {
                return (int) $port;
            }
        }

        // 从 $_SERVER 获取
        if (isset($_SERVER['SERVER_PORT'])) {
            return (int) $_SERVER['SERVER_PORT'];
        }

        return 9501;
    }
}
