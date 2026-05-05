<?php

/**
 * RPC 客户端配置文件
 * 
 * 本配置文件用于管理 think-swoole-rpc-client-sdk 的所有配置项
 * 所有配置项均支持通过环境变量覆盖，便于不同环境（开发、测试、生产）的差异化配置
 * 
 * @package qs9000\rpc
 */

return [
    // ========================================
    // 注册中心配置 (Registry)
    // ========================================
    'registry' => [
        // 是否启用注册中心
        'enable' => env('RPC_REGISTRY_ENABLE', true),
        
        // 注册中心主机地址
        'host' => env('RPC_REGISTRY_HOST', '127.0.0.1'),
        
        // 注册中心端口
        'port' => (int) env('RPC_REGISTRY_PORT', 9500),
        
        // 请求超时时间（毫秒）
        'timeout' => (int) env('RPC_REGISTRY_TIMEOUT', 5000),
        
        // 连接超时时间（毫秒）
        'connect_timeout' => (int) env('RPC_REGISTRY_CONNECT_TIMEOUT', 1000),
        
        // 心跳间隔（秒）- 服务保活频率
        'heartbeat_interval' => (int) env('RPC_HEARTBEAT_INTERVAL', 30),
        
        // 认证 Token（如果注册中心需要鉴权）
        'token' => env('RPC_REGISTRY_TOKEN', null),
        
        // 基础 URL（可选，如果不设置则自动根据 host:port 生成）
        'base_url' => env('RPC_REGISTRY_BASE_URL', null),
    ],

    // ========================================
    // 服务发现配置 (Service Discovery)
    // ========================================
    'discovery' => [
        // 负载均衡策略：random, roundrobin, weight, leastconnection, consistenthash
        'loadbalancer' => env('RPC_LOADBALANCER', 'random'),
        
        // 本地缓存 TTL（秒）- 服务列表在客户端的缓存时间
        'cache_ttl' => (int) env('RPC_CACHE_TTL', 30),
        
        // 优雅降级开关 - 当注册中心不可用时，是否使用过期缓存
        'enable_graceful_degradation' => (bool) env('RPC_ENABLE_GRACEFUL_DEGRADATION', true),
        
        // 健康检查过滤 - 是否只返回健康的实例
        'health_check_enabled' => (bool) env('RPC_HEALTH_CHECK_ENABLED', true),
    ],

    // ========================================
    // 熔断器配置 (Circuit Breaker)
    // ========================================
    'circuitbreaker' => [
        // 失败阈值 - 连续失败多少次后开启熔断
        'failure_threshold' => (int) env('RPC_CIRCUIT_FAILURE_THRESHOLD', 5),
        
        // 成功阈值 - 半开状态下连续成功多少次后恢复
        'success_threshold' => (int) env('RPC_CIRCUIT_SUCCESS_THRESHOLD', 3),
        
        // 熔断超时（秒）- 熔断开启多久后进入半开状态
        'timeout' => (int) env('RPC_CIRCUIT_TIMEOUT', 60),
        
        // 请求超时（毫秒）- 保留用于兼容性
        'request_timeout' => (int) env('RPC_CIRCUIT_REQUEST_TIMEOUT', 5000),
    ],

    // ========================================
    // RPC 调用配置 (Call Configuration)
    // ========================================
    
    // 全局调用超时时间（秒）
    'timeout' => (int) env('RPC_TIMEOUT', 5),
    
    // 重试次数 - 失败后的自动重试次数
    'tries' => (int) env('RPC_RETRIES', 2),
    
    // 退避算法基数（毫秒）- 重试时的等待基数
    'backoff_base' => (int) env('RPC_BACKOFF_BASE', 100),
    
    // 最大退避时间（毫秒）- 重试等待的最大值
    'backoff_max' => (int) env('RPC_BACKOFF_MAX', 1000),

    // ========================================
    // 连接池配置 (Connection Pool)
    // ========================================
    'connection' => [
        // 是否启用连接池
        'enable_connection_pool' => (bool) env('RPC_ENABLE_CONNECTION_POOL', true),
        
        // 连接池最大连接数 - 每个实例的最大长连接数
        'max_connections' => (int) env('RPC_MAX_CONNECTIONS', 20),
        
        // 连接超时时间（秒）- 建立 TCP 连接的超时时间
        'connect_timeout' => (int) env('RPC_CONNECT_TIMEOUT', 1),
        
        // 最小连接数（预留，未来可扩展）
        'min_connections' => (int) env('RPC_MIN_CONNECTIONS', 1),
        
        // 连接空闲超时（秒）- 连接空闲多久后关闭（预留）
        'idle_timeout' => (int) env('RPC_IDLE_TIMEOUT', 300),
        
        // 健康检查间隔（秒）- 定期检查连接可用性的频率
        'health_check_interval' => (int) env('RPC_HEALTH_CHECK_INTERVAL', 60),
    ],

    // ========================================
    // 协议配置 (Protocol)
    // ========================================
    'protocol' => [
        // 数据格式：json (目前仅支持 JSON-RPC 2.0)
        'format' => env('RPC_PROTOCOL_FORMAT', 'json'),
        
        // 包结束符 - Swoole 粘包处理的结束符
        'package_eof' => env('RPC_PACKAGE_EOF', "\r\n"),
        
        // 是否启用 EOF 检测
        'open_eof_check' => (bool) env('RPC_OPEN_EOF_CHECK', true),
    ],

    // ========================================
    // 性能优化配置
    // ========================================
    'performance' => [
        'enable_protocol_cache' => (bool) env('RPC_ENABLE_PROTOCOL_CACHE', true),
        'protocol_cache_ttl' => (int) env('RPC_PROTOCOL_CACHE_TTL', 3600),
        'enable_batch_discovery' => (bool) env('RPC_ENABLE_BATCH_DISCOVERY', false),
        'batch_discovery_size' => (int) env('RPC_BATCH_DISCOVERY_SIZE', 10),
        'enable_async_heartbeat' => (bool) env('RPC_ENABLE_ASYNC_HEARTBEAT', true),
        'connection_warmup' => (bool) env('RPC_CONNECTION_WARMUP', false),
        'warmup_connections' => (int) env('RPC_WARMUP_CONNECTIONS', 5),
    ],

    // ========================================
    // 负载均衡策略注册 (Load Balancer Strategies)
    // ========================================
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

    // ========================================
    // 日志与调试配置 (Logging & Debug)
    // ========================================
    'debug' => [
        // 调试模式 - 是否输出详细日志
        'enabled' => (bool) env('RPC_DEBUG', false),
        
        // 日志级别：error, warning, info, debug
        'log_level' => env('RPC_LOG_LEVEL', 'error'),
        
        // 是否记录熔断状态变更日志
        'log_circuit_breaker' => (bool) env('RPC_LOG_CIRCUIT_BREAKER', true),
        
        // 是否记录服务发现日志
        'log_service_discovery' => (bool) env('RPC_LOG_SERVICE_DISCOVERY', false),
        
        // 是否记录连接池统计信息
        'log_connection_pool' => (bool) env('RPC_LOG_CONNECTION_POOL', false),
    ],

    // ========================================
    // 监控配置 (Monitoring)
    // ========================================
    'monitoring' => [
        // 是否启用监控指标收集
        'enabled' => (bool) env('RPC_MONITORING_ENABLED', false),
        
        // 监控指标上报间隔（秒）
        'interval' => (int) env('RPC_MONITORING_INTERVAL', 60),
        
        // 监控端点 URL（预留，未来可集成 Prometheus 等）
        'endpoint' => env('RPC_MONITORING_ENDPOINT', null),
    ],

    // ========================================
    // 安全配置 (Security)
    // ========================================
    'security' => [
        'enable_tls' => (bool) env('RPC_ENABLE_TLS', false),
        'tls_verify_peer' => (bool) env('RPC_TLS_VERIFY_PEER', true),
        'request_signing' => (bool) env('RPC_REQUEST_SIGNING', false),
        'signing_algorithm' => env('RPC_SIGNING_ALGORITHM', 'HMAC-SHA256'),
        'secret_key' => env('RPC_SECRET_KEY', null),
        'allowed_ips' => env('RPC_ALLOWED_IPS', null),
        'rate_limit_enabled' => (bool) env('RPC_RATE_LIMIT_ENABLED', false),
        'rate_limit_requests' => (int) env('RPC_RATE_LIMIT_REQUESTS', 1000),
        'rate_limit_period' => (int) env('RPC_RATE_LIMIT_PERIOD', 60),
    ],

    // ========================================
    // RPC 接口绑定配置 (Interface Binding)
    // ========================================
    'interfaces' => [
        // rpc.php 文件路径（相对于应用根目录）
        'rpc_file' => env('RPC_INTERFACES_FILE', 'rpc.php'),
        
        // 是否自动绑定 rpc.php 中声明的接口
        'auto_bind' => (bool) env('RPC_AUTO_BIND_INTERFACES', true),
    ],

    // ========================================
    // 中间件配置 (Middleware)
    // ========================================
    // 参考 think-swoole 的设计，中间件只需要类名
    // 用户自己在中间件的 handle 方法中通过 $protocol 对象读取/写入参数
    'middleware' => [
        // 全局中间件列表（所有 RPC 调用都会执行）
        // \qs9000\rpc\middleware\InjectParamsMiddleware::class,
        // \qs9000\rpc\middleware\AuthMiddleware::class,
    ],

    // ========================================
    // 中间件参数配置 (Middleware Parameters)
    // ========================================
    // 中间件从这里读取配置参数，而不是在 middleware 数组中传递
    'middleware_params' => [
        // 参数注入中间件的配置
        'inject' => [
            // 在这里配置需要注入的公共参数
            // 'app_id' => 'my_app',
            // 'version' => '1.0.0',
        ],
        
        // 认证中间件的配置
        'auth' => [
            'token' => env('RPC_AUTH_TOKEN', ''),
            'field' => 'auth_token',  // 字段名
        ],
    ],
];
