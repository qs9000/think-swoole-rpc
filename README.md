# qs9000/think-swoole-rpc - 基于 ThinkPHP + Swoole 的高性能 RPC 框架

## 📖 简介

`qs9000/think-swoole-rpc` 是一个为 ThinkPHP 8 + Swoole 环境设计的企业级 RPC 框架，提供完整的服务注册与发现、负载均衡、熔断保护、连接池管理、中间件支持等微服务核心能力。
本项目是我的自用项目，若需要使用，请自行检查和修改适配。

---

## ✨ 核心特性

- **服务注册与发现**：支持注册中心（HTTP/RPC 双协议），服务自动注册、心跳保活、优雅下线。
- **多种负载均衡策略**：随机、轮询、加权轮询（平滑加权）、一致性哈希、最少连接（需自行扩展）。
- **熔断器**：基于状态的熔断保护（CLOSED → OPEN → HALF_OPEN），防止级联故障。
- **连接池**：基于 Swoole 协程的高效连接复用，支持最小/最大连接数、空闲检测。
- **中间件体系**：客户端/服务端均支持中间件，内置认证（HMAC-SHA256 签名）、限流（固定窗口）、链路追踪（traceId 传递）。
- **服务缓存**：服务发现结果本地缓存（TTL 可配置），降低注册中心压力。
- **安全性**：请求签名、时间戳防重放、可配置的认证/限流规则。
- **易于扩展**：负载均衡策略、注册中心客户端、中间件均可自定义。

---

## 📦 安装

### 环境要求

- PHP 8.0+
- Swoole 4.8+（推荐 5.0+）
- ThinkPHP 8.0+
- 注册中心（需实现对应 API，可自行开发或使用第三方如 Nacos）

### 通过 Composer 安装

```bash
composer require qs9000/think-swoole-rpc
```

### 发布配置文件

框架会自动加载配置，你也可以手动发布配置文件到 `config/` 目录（具体命令视包设计而定，若无则手动创建）。

---

## ⚙️ 配置

### 1. 基础配置文件 `config/rpc.php`

```php
<?php

return [
    // 注册中心配置
    'registry' => [
        'host' => '127.0.0.1',          // 注册中心地址
        'port' => 9000,                 // 注册中心端口
        'timeout' => 5000,              // 请求超时(ms)
        'exclude_private' => false,     // 是否优先使用公网IP注册
        'rpc' => [                      // RPC 服务注册配置
            'enable' => true,
            'method' => 'rpc',          // rpc 或 http
            'heartbeat_interval' => 30, // 心跳间隔(秒)
        ],
        'server' => [                   // 服务器信息注册(可选)
            'enable' => true,
            'method' => 'rpc',
        ],
    ],

    // 客户端服务发现配置
    'discovery' => [
        'cache' => 'file',              // 缓存驱动
        'cache_ttl' => 30,              // 缓存 TTL(秒)
        'loadbalancer' => 'weight',     // 负载均衡策略: random/roundrobin/weight/consistenthash
        'strategies' => [
            // 自定义策略可在此注册
        ],
    ],

    // RPC 客户端配置
    'client' => [
        'tries' => 2,                   // 失败重试次数
        'pool' => [                     // 连接池配置
            'min_active' => 0,
            'max_active' => 10,
            'max_wait_time' => 5,
            'max_idle_time' => 20,
            'idle_check_interval' => 10,
        ],
        'middleware' => [
            \qs9000\rpc\middleware\RpcClientAuth::class,
            \qs9000\rpc\middleware\RpcClientInjectRequest::class,
        ],
        'circuitbreaker' => [           // 熔断器配置
            'failure_threshold' => 5,   // 连续失败多少次开启熔断
            'success_threshold' => 3,   // 半开状态连续成功多少次关闭
            'timeout' => 60,            // 熔断超时(秒)
        ],
        'auth' => [
            'secret' => env('RPC_CLIENT_SECRET'), // 可选，建议用环境变量
        ],
    ],

    // RPC 服务端配置
    'server' => [
        'auth' => [
            'enable' => false,
            'secret' => env('RPC_SERVER_SECRET'),
            'auth_class' => null,       // 自定义认证类
        ],
        'rate_limit' => [
            'enable' => false,
            'cache' => 'file',
            'limit' => 100,             // 限制次数
            'interval' => 60,           // 时间窗口(秒)
            'limit_class' => null,      // 自定义限流类
        ],
    ],
];
```

### 2. 环境变量 `.env` 配置（认证密钥）

```env
# 客户端认证密钥（与后端约定的共享密钥）
RPC_SECRET_MYAPP=your-secure-secret-key

# 服务端认证密钥（如果启用 auth.enable）
RPC_SERVER_SECRET=your-secure-secret-key
```
> `MYAPP` 为 `config('app.name')` 的大写形式。

### 3. 服务映射文件 `根目录/rpc.php`

此文件用于声明客户端需要调用的远程服务接口。

```php
<?php
// 文件位置：项目根目录/rpc.php
return [
    'UserService'   => \app\rpc\UserServiceInterface::class,
    'OrderService'  => \app\rpc\OrderServiceInterface::class,
];
```

### 4. 服务提供者注册

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
        'host' => '0.0.0.0',
        'port' => 9501,
        'services' => [
            'UserService' => \app\rpc\UserServiceInterface::class,
            // 可注册多个服务
        ],
        'weight' => 100,
        'metadata' => ['version' => '1.0'],
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

### 4. 手动清除服务发现缓存

```php
$discover = app(\qs9000\rpc\ServiceDiscover::class);
$discover->clearCache('UserService');
```

---

## 📂 目录结构

```
qs9000/rpc/
├── client/                 # 客户端相关
│   ├── BindInterface.php   # 服务绑定
│   └── Connector.php       # 连接器（含熔断器、连接池）
├── contract/               # 接口定义
├── loadbalancer/           # 负载均衡器实现
├── middleware/             # 中间件
├── registry/               # 注册中心客户端
├── server/                 # 服务端信息收集
├── CircuitBreaker.php      # 熔断器
├── RpcException.php        # 异常类
├── ServiceDiscover.php     # 服务发现
├── ServiceInstance.php     # 服务实例模型
├── ServiceRegister.php     # 服务注册器
└── RpcClientService.php    # 服务提供者
```

---

## 🧪 测试建议

- **注册中心模拟**：可使用 `php -S` 搭建简单 HTTP 服务模拟注册中心 API。
- **熔断器测试**：编写单元测试模拟连续失败，验证状态转换。
- **负载均衡分布**：多实例下打印选择的节点 IP，验证加权轮询效果。

---

## 🤝 贡献指南

欢迎提交 Issue 和 Pull Request。请确保代码符合 PSR-12 标准，并附带必要的单元测试。

---

## 📄 许可证

本项目采用 MIT 许可证。

---

## 📧 联系方式

如有问题，请联系项目维护者或在 GitHub 提交 Issue。