<?php

/**
 * RPC 配置文件
 * 
 */

return [
    // ========================================
    // 注册中心配置 (Registry)
    // ========================================
    'registry' => [
        'cache' => 'redis',
        'exclude_private' => false, //是否用公网IP注册
        'registry_class' => null, //自定义注册中心类,必须实现RegistryClientInterface
        'rpc' => [
            'enable' => true,
            'heartbeat_interval' => 30,
        ],
        'server' => [
            'enable' => true,
            'heartbeat_interval' => 30,
        ],
    ],

    // ========================================
    // 服务发现配置 (Service Discovery)
    // ========================================
    'discovery' => [
        'cache' => 'file', // 服务列表缓存方式，对应config/cache.php中的缓存配置项，默认为 file
        // 本地缓存 TTL（秒）- 服务列表在客户端的缓存时间
        'cache_ttl' => 30,
        // 优雅降级开关 - 当注册中心不可用时，是否使用过期缓存
        'enable_graceful_degradation' => true,
        // 健康检查过滤 - 是否只返回健康的实例
        'health_check_enabled' => true,
        // 负载均衡策略：random, roundrobin, weight, leastconnection, consistenthash
        'loadbalancer' => 'weight',
        'strategies' => [
            // 内置策略（无需修改）
            'random' => \qs9000\rpc\loadbalancer\RandomLoadBalancer::class,
            'roundrobin' => \qs9000\rpc\loadbalancer\RoundRobinLoadBalancer::class,
            'weight' => \qs9000\rpc\loadbalancer\WeightLoadBalancer::class,
            'leastconnection' => \qs9000\rpc\loadbalancer\LeastConnectionLoadBalancer::class,
            'consistenthash' => \qs9000\rpc\loadbalancer\ConsistentHashLoadBalancer::class,
            // 自定义策略示例（取消注释并修改为实际类名）
            // 'custom' => \App\Rpc\LoadBalancer\CustomLoadBalancer::class,
        ],
    ],
    /**
     * 客户端配置
     */
    'client' => [
        // 重试次数 - 失败后的自动重试次数
        'tries' => 2,
        // 本机直调开关 - 当目标服务 host:port 指向本机时，绕过 TCP 直接进程内调用
        'enable_local_call' => true,
        'pool' => [
            'min_active' => 0,
            'max_active' => 10,
            'max_wait_time' => 5,
            'max_idle_time' => 20,
            'idle_check_interval' => 10,
        ],
        'middleware' => [
            \qs9000\rpc\middleware\RpcClientAuth::class, // 添加客户端认证中间件
            \qs9000\rpc\middleware\RpcClientInjectRequest::class, // 添加客户端请求注入中间件
        ], //中间件配置
        // 熔断器配置 (Circuit Breaker)
        'circuitbreaker' => [
            'cache' => 'file', // 熔断器列表缓存方式，对应config/cache.php中的缓存配置项，默认为 file
            // 失败阈值 - 连续失败多少次后开启熔断
            'failure_threshold' => 5,
            // 成功阈值 - 半开状态下连续成功多少次后恢复
            'success_threshold' => 3,
            // 熔断超时（秒）- 熔断开启多久后进入半开状态
            'timeout' => 60,
            // 请求超时（毫秒）- 保留用于兼容性
            'request_timeout' => 5000,
        ],
    ],
    'server' => [
        'auth' => [
            'enable' => false,
            'cache' => 'system', //服务器密钥缓存，对应config/cache.php中的缓存配置项，默认为system
            'auth_class' => null // 自定义认证类 实现handle(Protocol $protocol)方法，不定义则使用默认的签名验证
        ],
        'rate_limit' => [
            'enable' => false,
            'cache' => 'file', // 限流缓存方式，对应config/cache.php中的缓存配置项，默认为 file
            'limit' => 100,
            'interval' => 60,
            'limit_class' => null, // 自定义限流类 实现handle(Protocol $protocol)方法,不定义则使用默认的固定窗口限流
        ],
    ]

];
