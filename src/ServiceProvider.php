<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\Service;
use think\App;
use qs9000\rpc\rpc\DiscoveryGateway;

/**
 * RPC 服务提供者
 * 
 * 在 ThinkPHP 应用启动时自动注册 RPC 相关服务到容器：
 * - RegistryClient: 注册中心客户端
 * - ServiceDiscovery: 服务发现器
 * - CircuitBreaker: 熔断器
 * - DiscoveryGateway: 动态服务发现网关（核心）
 * 
 * 使用方式：
 * 1. 在 config/app.php 中注册此 ServiceProvider
 * 2. 或在 bootstrap/app.php 中手动加载
 * 
 * @package qs9000\rpc
 */
class ServiceProvider extends Service
{
    /**
     * 注册服务到容器
     */
    public function register(): void
    {
        // 1. 注册配置（如果尚未配置）
        $this->registerConfig();

        // 2. 注册单例服务
        $this->registerServices();

        // 3. 注册动态网关（关键！）
        $this->registerDiscoveryGateway();
    }

    /**
     * 注册配置
     * 
     * 优先使用项目中的 config/rpc.php 文件，
     * 如果不存在则设置默认配置
     */
    protected function registerConfig(): void
    {
        // 检查是否已有配置
        if ($this->app->config->has('rpc')) {
            return;
        }

        // 尝试加载 config/rpc.php 文件
        $configFile = $this->getConfigFilePath();
        
        if (file_exists($configFile)) {
            // 从文件加载配置
            $config = include $configFile;
            $this->app->config->set($config, 'rpc');
            
            // 验证配置
            $this->validateConfig($config);
        } else {
            // 设置默认配置（兼容旧版本）
            $defaultConfig = [
                // 注册中心配置
                'registry' => [
                    'enable' => (bool) env('RPC_REGISTRY_ENABLE', true),
                    'host' => env('RPC_REGISTRY_HOST', '127.0.0.1'),
                    'port' => (int) env('RPC_REGISTRY_PORT', 9500),
                    'timeout' => (int) env('RPC_REGISTRY_TIMEOUT', 5000),
                    'connect_timeout' => (int) env('RPC_REGISTRY_CONNECT_TIMEOUT', 1000),
                    'heartbeat_interval' => (int) env('RPC_HEARTBEAT_INTERVAL', 30),
                    'token' => env('RPC_REGISTRY_TOKEN', null),
                ],

                // 服务发现配置
                'discovery' => [
                    'loadbalancer' => env('RPC_LOADBALANCER', 'random'),
                    'cache_ttl' => (int) env('RPC_CACHE_TTL', 30),
                    'enable_graceful_degradation' => (bool) env('RPC_ENABLE_GRACEFUL_DEGRADATION', true),
                    'health_check_enabled' => (bool) env('RPC_HEALTH_CHECK_ENABLED', true),
                ],

                // 熔断器配置
                'circuitbreaker' => [
                    'failure_threshold' => (int) env('RPC_CIRCUIT_FAILURE_THRESHOLD', 5),
                    'success_threshold' => (int) env('RPC_CIRCUIT_SUCCESS_THRESHOLD', 3),
                    'timeout' => (int) env('RPC_CIRCUIT_TIMEOUT', 60),
                    'request_timeout' => (int) env('RPC_CIRCUIT_REQUEST_TIMEOUT', 5000),
                ],

                // 调用配置
                'timeout' => (int) env('RPC_TIMEOUT', 5),
                'tries' => (int) env('RPC_RETRIES', 2),
                'backoff_base' => (int) env('RPC_BACKOFF_BASE', 100),
                'backoff_max' => (int) env('RPC_BACKOFF_MAX', 1000),

                // 连接池配置
                'connection' => [
                    'max_connections' => (int) env('RPC_MAX_CONNECTIONS', 20),
                    'connect_timeout' => (int) env('RPC_CONNECT_TIMEOUT', 1),
                    'min_connections' => (int) env('RPC_MIN_CONNECTIONS', 1),
                    'idle_timeout' => (int) env('RPC_IDLE_TIMEOUT', 300),
                ],

                // 协议配置
                'protocol' => [
                    'format' => env('RPC_PROTOCOL_FORMAT', 'json'),
                    'package_eof' => env('RPC_PACKAGE_EOF', "\r\n"),
                    'open_eof_check' => (bool) env('RPC_OPEN_EOF_CHECK', true),
                ],

                // 负载均衡策略
                'strategies' => [
                    'random' => \qs9000\rpc\loadbalancer\RandomLoadBalancer::class,
                    'roundrobin' => \qs9000\rpc\loadbalancer\RoundRobinLoadBalancer::class,
                    'weight' => \qs9000\rpc\loadbalancer\WeightLoadBalancer::class,
                    'leastconnection' => \qs9000\rpc\loadbalancer\LeastConnectionLoadBalancer::class,
                    'consistenthash' => \qs9000\rpc\loadbalancer\ConsistentHashLoadBalancer::class,
                ],

                // 调试配置
                'debug' => [
                    'enabled' => (bool) env('RPC_DEBUG', false),
                    'log_level' => env('RPC_LOG_LEVEL', 'error'),
                    'log_circuit_breaker' => (bool) env('RPC_LOG_CIRCUIT_BREAKER', true),
                    'log_service_discovery' => (bool) env('RPC_LOG_SERVICE_DISCOVERY', false),
                    'log_connection_pool' => (bool) env('RPC_LOG_CONNECTION_POOL', false),
                ],

                // 接口绑定配置
                'interfaces' => [
                    'rpc_file' => env('RPC_INTERFACES_FILE', 'rpc.php'),
                    'auto_bind' => (bool) env('RPC_AUTO_BIND_INTERFACES', true),
                ],
            ];
            
            $this->app->config->set(['rpc' => $defaultConfig], 'rpc');
            
            // 验证默认配置
            $this->validateConfig($defaultConfig);
        }
    }

    /**
     * 验证配置
     * 
     * @param array $config 配置数组
     * @throws \InvalidArgumentException 配置无效时抛出异常
     */
    protected function validateConfig(array $config): void
    {
        // 验证注册中心配置
        $registry = $config['registry'] ?? [];
        
        if (empty($registry['host'])) {
            throw new \InvalidArgumentException('RPC registry host is required');
        }
        
        $port = $registry['port'] ?? 0;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid registry port: ' . $port);
        }
        
        $timeout = $registry['timeout'] ?? 0;
        if ($timeout < 100) {
            throw new \InvalidArgumentException('Registry timeout must be at least 100ms');
        }
        
        // 验证调用超时
        $callTimeout = $config['timeout'] ?? 0;
        if ($callTimeout < 1) {
            throw new \InvalidArgumentException('RPC timeout must be at least 1 second');
        }
        
        // 验证重试次数
        $tries = $config['tries'] ?? 0;
        if ($tries < 0 || $tries > 10) {
            throw new \InvalidArgumentException('RPC retries must be between 0 and 10');
        }
        
        // 验证熔断器配置
        $circuitBreaker = $config['circuitbreaker'] ?? [];
        $failureThreshold = $circuitBreaker['failure_threshold'] ?? 0;
        if ($failureThreshold < 1) {
            throw new \InvalidArgumentException('Circuit breaker failure threshold must be at least 1');
        }
        
        // 验证连接池配置
        $connection = $config['connection'] ?? [];
        $maxConnections = $connection['max_connections'] ?? 0;
        if ($maxConnections < 1) {
            throw new \InvalidArgumentException('Max connections must be at least 1');
        }
    }

    /**
     * 注册单例服务
     */
    protected function registerServices(): void
    {
        // RegistryClient - 注册中心客户端（使用 bind 实现单例）
        $this->app->bind(RegistryClient::class, function (App $app) {
            $config = $app->config->get('rpc.registry', []);
            
            $instance = new RegistryClient(
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 9500,
                $config['timeout'] ?? 5000
            );
            
            // 设置认证 Token（如果配置了）
            if (!empty($config['token'])) {
                $instance->setToken($config['token']);
            }
            
            return $instance;
        }, true); // 第三个参数 true 表示单例

        // ServiceDiscovery - 服务发现器（使用 bind 实现单例）
        $this->app->bind(ServiceDiscovery::class, function (App $app) {
            $registryClient = $app->get(RegistryClient::class);
            $discoveryConfig = $app->config->get('rpc.discovery', []);
            
            $instance = new ServiceDiscovery($registryClient);
            
            // 设置负载均衡策略
            if (isset($discoveryConfig['loadbalancer'])) {
                $instance->setLoadBalancerStrategy($discoveryConfig['loadbalancer']);
            }
            
            // 设置缓存 TTL
            if (isset($discoveryConfig['cache_ttl'])) {
                $instance->setCacheTtl($discoveryConfig['cache_ttl']);
            }
            
            return $instance;
        }, true); // 第三个参数 true 表示单例

        // CircuitBreaker - 熔断器（使用 bind 实现单例）
        $this->app->bind(CircuitBreaker::class, function (App $app) {
            $config = $app->config->get('rpc.circuitbreaker', []);
            return new CircuitBreaker($config);
        }, true); // 第三个参数 true 表示单例
    }

    /**
     * 注册动态服务发现网关（核心功能）
     * 
     * 这是解决"启动时固定配置"问题的关键：
     * - 不依赖固定的 host/port 配置
     * - 每次调用时通过 ServiceDiscovery 动态获取可用实例
     * - 支持运行时新注册的服务
     */
    protected function registerDiscoveryGateway(): void
    {
        $this->app->bind(DiscoveryGateway::class, function (App $app) {
            $discovery = $app->get(ServiceDiscovery::class);
            $circuitBreaker = $app->get(CircuitBreaker::class);
            $config = $app->config->get('rpc', []);

            return new DiscoveryGateway(
                $discovery,
                $circuitBreaker,
                $config['discovery']['loadbalancer'] ?? 'random',
                $config['timeout'] ?? 5,
                $config['tries'] ?? 2,
                $config['connection']['connect_timeout'] ?? 1
            );
        }, true); // 第三个参数 true 表示单例
    }

    /**
     * 启动时的操作
     */
    public function boot(): void
    {
        // 自动绑定 rpc.php 中声明的接口
        $this->bindRpcInterfaces();
    }

    /**
     * 绑定 RPC 接口（处理 rpc.php 文件）
     * 
     * 这是解决 "rpc.php 文件在动态服务发现中如何使用" 的关键：
     * - 读取 base_path() . 'rpc.php' 文件
     * - 将所有声明的接口绑定到 DiscoveryGateway
     * - 通过动态代理实现透明调用
     */
    protected function bindRpcInterfaces(): void
    {
        // 检查是否启用自动绑定
        $interfacesConfig = $this->app->config->get('rpc.interfaces', []);
        if (!($interfacesConfig['auto_bind'] ?? true)) {
            return;
        }

        // 获取 rpc.php 文件路径
        $rpcFileName = $interfacesConfig['rpc_file'] ?? 'rpc.php';
        $rpcFile = $this->app->getBasePath() . $rpcFileName;
        
        if (!file_exists($rpcFile)) {
            // 文件不存在，跳过绑定
            return;
        }

        // 创建接口绑定器
        $binder = new RpcInterfaceBinder(
            $this->app,
            $this->app->get(DiscoveryGateway::class)
        );

        // 绑定所有接口
        $binder->bindAll();
    }

    /**
     * 获取配置文件路径
     * 
     * @return string 配置文件完整路径
     */
    protected function getConfigFilePath(): string
    {
        // 优先使用应用配置目录
        $appConfigPath = $this->app->getConfigPath();
        if (!empty($appConfigPath)) {
            return rtrim($appConfigPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rpc.php';
        }

        // 回退到 SDK 内置配置
        return __DIR__ . '/../config/rpc.php';
    }
}
