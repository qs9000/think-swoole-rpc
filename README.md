# think-swoole-rpc - 基于 ThinkPHP + Swoole 的高性能 RPC 框架

## 📖 简介

`think-swoole-rpc` 是一个为 ThinkPHP 8 + Swoole 环境设计的企业级 RPC 框架，提供完整的服务注册与发现、负载均衡、熔断保护、连接池管理、中间件支持等微服务核心能力。

本项目是我的自用项目，若需要使用，请自行检查和修改适配。

---

## ✨ 核心特性

- **服务注册与发现**：支持基于 Redis 的注册中心，服务自动注册、心跳保活、优雅下线。
- **多种负载均衡策略**：随机、轮询、加权轮询、一致性哈希、最少连接（需自行扩展）。
- **熔断器**：基于状态的熔断保护（CLOSED → OPEN → HALF_OPEN），防止级联故障。
- **连接池**：基于 Swoole 协程的高效连接复用，支持最小/最大连接数、空闲检测。
- **中间件体系**：客户端/服务端均支持中间件，内置认证（HMAC-SHA256 签名）、限流（固定窗口）、链路追踪。
- **服务缓存**：服务发现结果本地缓存（TTL 可配置），降低注册中心压力。
- **安全性**：请求签名、时间戳防重放、可配置的认证/限流规则。
- **易于扩展**：负载均衡策略、注册中心客户端、中间件均可自定义。
- **协程安全**：在 Swoole 环境下使用协程锁确保 Redis 连接安全。

---

## 📦 安装

### 环境要求

- PHP 8.0+
- Swoole 4.8+（推荐 5.0+）
- ThinkPHP 8.0+
- Redis（作为注册中心和熔断器存储）

### 通过 Composer 安装

```bash
composer require qs9000/think-swoole-rpc
```

---

## ⚙️ 配置

### 1. 基础配置文件 `config/rpc.php`

```php
<?php

return [
    // ========================================
    // 注册中心配置 (Registry)
    // ========================================
    'registry' => [
        'cache' => 'redis',              // 注册中心存储驱动（对应 cache.php 中的配置）
        'exclude_private' => false,      // 是否用公网IP注册
        'registry_class' => null,        // 自定义注册中心类,必须实现RegistryClientInterface
        'rpc' => [
            'enable' => true,            // 是否启用 RPC 服务注册
            'heartbeat_interval' => 30,  // 心跳间隔(秒)
        ],
        'server' => [
            'enable' => true,            // 是否启用 服务器信息注册
            'heartbeat_interval' => 30,  // 心跳间隔(秒)
        ],
    ],

    // ========================================
    // 服务发现配置 (Service Discovery)
    // ========================================
    'discovery' => [
        'cache' => 'file',                          // 服务列表缓存方式，对应config/cache.php中的缓存配置项
        'cache_ttl' => 30,                         // 本地缓存 TTL（秒）
        'enable_graceful_degradation' => true,      // 优雅降级开关 - 当注册中心不可用时，是否使用过期缓存
        'health_check_enabled' => true,             // 健康检查过滤 - 是否只返回健康的实例
        'loadbalancer' => 'weight',                // 负载均衡策略
        'strategies' => [                          // 负载均衡策略映射
            'random' => \qs9000\rpc\loadbalancer\RandomLoadBalancer::class,
            'roundrobin' => \qs9000\rpc\loadbalancer\RoundRobinLoadBalancer::class,
            'weight' => \qs9000\rpc\loadbalancer\WeightLoadBalancer::class,
            'leastconnection' => \qs9000\rpc\loadbalancer\LeastConnectionLoadBalancer::class,
            'consistenthash' => \qs9000\rpc\loadbalancer\ConsistentHashLoadBalancer::class,
        ],
    ],

    // ========================================
    // 客户端配置
    // ========================================
    'client' => [
        'tries' => 2,                              // 重试次数 - 失败后的自动重试次数
        'pool' => [                                // 连接池配置
            'min_active' => 0,
            'max_active' => 10,
            'max_wait_time' => 5,
            'max_idle_time' => 20,
            'idle_check_interval' => 10,
        ],
        'middleware' => [                          // 中间件配置
            \qs9000\rpc\middleware\RpcClientAuth::class,
            \qs9000\rpc\middleware\RpcClientInjectRequest::class,
        ],
        'circuitbreaker' => [                      // 熔断器配置
            'cache' => 'file',                     // 熔断器列表缓存方式
            'failure_threshold' => 5,              // 连续失败多少次后开启熔断
            'success_threshold' => 3,              // 半开状态下连续成功多少次后恢复
            'timeout' => 60,                       // 熔断超时（秒）
            'request_timeout' => 5000,             // 请求超时（毫秒）
        ],
    ],

    // ========================================
    // 服务端配置
    // ========================================
    'server' => [
        'auth' => [
            'enable' => false,                     // 是否启用认证
            'cache' => 'system',                   // 服务器密钥缓存
            'auth_class' => null                   // 自定义认证类
        ],
        'rate_limit' => [
            'enable' => false,                     // 是否启用限流
            'cache' => 'file',                     // 限流缓存方式
            'limit' => 100,                        // 限制次数
            'interval' => 60,                      // 时间窗口(秒)
            'limit_class' => null                  // 自定义限流类
        ],
    ]
];
```

### 2. 服务映射文件 `根目录/rpc.php`

此文件用于声明客户端需要调用的远程服务接口。

```php
<?php
// 文件位置：项目根目录/rpc.php
return [
    'UserService'   => \app\rpc\UserServiceInterface::class,
    'OrderService'  => \app\rpc\OrderServiceInterface::class,
];
```

### 3. 服务提供者注册

在 `config/service.php` 中添加：

```php
<?php
return [
    \qs9000\rpc\RpcClientService::class,
];
```

---

## 🚀 使用指南

### 一、作为 RPC 服务端

#### 1. 定义服务接口与实现

```php
// 接口定义
namespace app\rpc;
interface UserServiceInterface
{
    public function getUserInfo(int $uid): array;
}

// 实现类
namespace app\rpc\service;
use app\rpc\UserServiceInterface;
class UserService implements UserServiceInterface
{
    public function getUserInfo(int $uid): array
    {
        // 业务逻辑
        return ['uid' => $uid, 'name' => 'user' . $uid];
    }
}
```

#### 2. 注册服务到 Swoole 配置

在 `config/swoole.php` 中添加 RPC 服务配置：

```php
'servers' => [
    // ...
    'rpc' => [
        'enable' => true,                                    // 启用 RPC 服务器
        'server' => [
            'host' => '0.0.0.0',
            'port' => 9501,
            'services' => [
                \app\rpc\UserServiceInterface::class,
                // 可注册多个服务
            ],
            'weight' => 100,
            'metadata' => ['version' => '1.0'],
        ],
    ],
],
```

#### 3. 启动 Swoole 服务

```bash
php think swoole:start
```

服务端会自动向注册中心注册本机提供的服务，并定时发送心跳。

### 二、作为 RPC 客户端

#### 1. 定义客户端接口（与服务端一致）

```php
namespace app\rpc;
interface UserServiceInterface
{
    public function getUserInfo(int $uid): array;
}
```

#### 2. 在控制器中注入使用

```php
<?php
namespace app\controller;

use app\rpc\UserServiceInterface;

class UserController
{
    public function index(UserServiceInterface $userService)
    {
        $result = $userService->getUserInfo(123);
        return json($result);
    }
}
```

#### 3. 调用流程

- 根据 `rpc.php` 中的服务名 `UserService` 进行服务发现。
- 从注册中心获取可用实例列表（本地缓存 TTL 30 秒）。
- 通过负载均衡选择一个实例。
- 检查熔断器状态，允许则从连接池借用连接。
- 发送请求，记录成功/失败，自动重试（tries=2）。

---

## 🔧 核心组件详解

### 1. RegistryClient - 注册中心客户端

- 负责与注册中心的所有通信
- 提供服务注册/注销、服务发现、心跳保活、健康检查功能
- 在 Swoole 环境下使用协程锁确保 Redis 连接安全

### 2. ServiceRegister - 服务注册器

- 负责在 Swoole 服务器启动时将服务信息注册到注册中心
- 通过监听 Swoole 的初始化事件，自动读取配置并执行注册逻辑
- 实现心跳机制和优雅下线

### 3. ServiceDiscover - 服务发现

- 从注册中心获取 RPC 服务实例信息
- 通过缓存和负载均衡策略返回可用的服务实例
- 实现缓存防击穿机制

### 4. CircuitBreaker - 熔断器

- 基于 Redis 的分布式熔断器
- 实现状态机模式（关闭/打开/半开）
- 使用 Lua 脚本保证原子操作

### 5. Connector - 连接器

- 基于 Swoole 协程的连接池管理
- 集成熔断器和负载均衡
- 确保连接的正确借用和归还

---

## 🧩 高级特性

### 1. 自定义负载均衡策略

实现 `LoadBalancerInterface`，并在 `config/rpc.discovery.strategies` 注册：

```php
'strategies' => [
    'custom' => \app\rpc\loadbalancer\CustomLoadBalancer::class,
],
```

### 2. 自定义中间件

实现 `MiddlewareInterface`，并在配置中启用：

```php
// 客户端中间件
'client' => [
    'middleware' => [
        \app\rpc\middleware\CustomMiddleware::class,
    ],
];
// 服务端中间件同理
```

### 3. 熔断器监控

```php
$circuitBreaker = app(\qs9000\rpc\CircuitBreaker::class);
$stats = $circuitBreaker->getStats('UserService_192.168.1.1:9501');
```

### 4. 连接池管理

```php
$connector = app(\qs9000\rpc\client\Connector::class);
$connector->close(); // 关闭所有连接池
```

---

## 📂 目录结构

```
qs9000/rpc/
├── client/                 # 客户端相关
│   ├── BindInterface.php   # 服务绑定
│   └── Connector.php       # 连接器（含熔断器、连接池）
├── config/                 # 配置文件
│   └── rpc.php             # 主配置文件
├── contract/               # 接口定义
├── loadbalancer/           # 负载均衡器实现
├── middleware/             # 中间件
├── CircuitBreaker.php      # 熔断器
├── RpcException.php        # 异常类
├── RegistryClient.php      # 注册中心客户端
├── ServiceDiscover.php     # 服务发现
├── ServiceInstance.php     # 服务实例模型
├── ServiceRegister.php     # 服务注册器
└── RpcClientService.php    # 服务提供者
```

---

## 🧪 测试建议

- **注册中心模拟**：可使用 Redis 模拟注册中心存储
- **熔断器测试**：编写单元测试模拟连续失败，验证状态转换
- **负载均衡分布**：多实例下验证负载均衡策略效果
- **连接池测试**：验证连接的正确借用和归还

---

## 🤝 贡献指南

欢迎提交 Issue 和 Pull Request。请确保代码符合 PSR-12 标准，并附带必要的单元测试。

---

## 📄 许可证

本项目采用 MIT 许可证。

---

## 📧 联系方式

如有问题，请联系项目维护者或在 GitHub 提交 Issue。