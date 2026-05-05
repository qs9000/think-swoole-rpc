# Think-Swoole RPC Client SDK

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.0-blue.svg)](https://php.net)
[![ThinkPHP](https://img.shields.io/badge/thinkphp-8.x-red.svg)](https://www.thinkphp.cn)
[![Swoole](https://img.shields.io/badge/swoole-%3E%3D4.2.9-orange.svg)](https://www.swoole.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)](CHANGELOG.md)

**企业级高性能 ThinkPHP 8 + Swoole RPC 客户端 SDK**

专为微服务架构设计，提供服务发现、智能负载均衡、熔断保护、中间件系统等完整解决方案。

---

## 🌟 核心特性

### 🚀 高性能
- **协程驱动**：基于 Swoole 协程实现非阻塞 I/O
- **连接池管理**：TCP 长连接复用，减少握手开销
- **智能重试**：失败自动切换实例，指数退避策略

### 🔍 服务发现
- **动态注册**：从注册中心自动获取服务实例
- **本地缓存**：可配置 TTL，减少网络请求
- **优雅降级**：注册中心不可用时使用缓存

### ⚖️ 负载均衡
- **5 种策略**：随机、轮询、最少连接、权重、一致性哈希
- **可扩展**：支持自定义负载均衡算法

### 🛡️ 熔断保护
- **三态状态机**：CLOSED → OPEN → HALF_OPEN
- **快速失败**：避免雪崩效应
- **自动恢复**：半开状态探测服务恢复

### 🔌 中间件系统
- **灵活扩展**：在请求前后执行自定义逻辑
- **简单配置**：只需类名，无需复杂配置
- **直接操作**：通过 `$protocol` 对象读写参数
- **内置中间件**：参数注入、认证等常用功能

### 🔒 安全防护
- **TLS/SSL**：端到端加密传输
- **请求签名**：HMAC-SHA256 防篡改
- **Token 认证**：注册中心身份验证

### 📦 双协议支持
- **TCP (Swoole)**：高性能协程客户端
- **HTTP**：标准 HTTP 客户端，跨语言调用

---

## 📋 目录

- [安装](#-安装)
- [快速开始](#-快速开始)
- [基础使用](#-基础使用)
- [中间件系统](#-中间件系统)
- [配置指南](#-配置指南)
- [高级特性](#-高级特性)
- [最佳实践](#-最佳实践)
- [常见问题](#-常见问题)
- [文档索引](#-文档索引)
- [贡献指南](#-贡献指南)

---

## 📦 安装

### 系统要求

- PHP >= 8.0
- ThinkPHP 8.x
- Swoole >= 4.2.9
- ext-json

### Composer 安装

```bash
composer require qs9000/think-swoole-rpc
```

---

## 🚀 快速开始

### 1. 注册服务提供者

在 `app/provider.php` 中添加：

```php
return [
    'providers' => [
        qs9000\rpc\ServiceProvider::class,
    ],
];
```

### 2. 配置环境变量

创建或编辑 `.env` 文件：

```bash
# 注册中心地址
RPC_REGISTRY_HOST=127.0.0.1
RPC_REGISTRY_PORT=9500

# 负载均衡策略
RPC_LOADBALANCER=random

# 超时和重试
RPC_TIMEOUT=5
RPC_RETRIES=2
```

### 3. 使用客户端

```php
<?php
namespace app\controller;

use qs9000\rpc\SwooleRpcClient;

class UserController
{
    protected SwooleRpcClient $rpcClient;
    
    public function __construct(SwooleRpcClient $rpcClient)
    {
        $this->rpcClient = $rpcClient;
    }
    
    public function getUser(int $id)
    {
        try {
            $result = $this->rpcClient->call(
                'UserService',    // 服务名称
                'getUser',         // 方法名
                ['id' => $id]     // 参数
            );
            
            return json(['code' => 0, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'message' => $e->getMessage()]);
        }
    }
}
```

---

## 💻 基础使用

### 基本调用

```php
// 同步调用
$result = $client->call('UserService', 'getUser', ['id' => 1]);

// 带版本号
$result = $client->call('UserService', 'getUser', ['id' => 1], 'v2');
```

### 错误处理

```php
use think\swoole\exception\RpcClientException;
use think\swoole\exception\RpcResponseException;
use qs9000\rpc\RpcException;

try {
    $result = $client->call('UserService', 'getUser', ['id' => 1]);
    
} catch (RpcResponseException $e) {
    // 业务逻辑错误（不应重试）
    echo "业务错误: " . $e->getMessage();
    
} catch (RpcClientException $e) {
    // 网络错误（会自动重试）
    echo "网络错误: " . $e->getMessage();
    
} catch (RpcException $e) {
    // 熔断器开启或服务不可用
    if ($e->getCode() === -32000) {
        echo "服务熔断，请稍后重试";
    } elseif ($e->getCode() === -32001) {
        echo "无可用服务实例";
    }
}
```

### 自定义配置

```php
// 设置超时时间（毫秒）
$client->setTimeout(10000);

// 设置重试次数
$client->setRetryTimes(3);

// 切换负载均衡策略
$client->setLoadBalancer('roundrobin');
```

---

## 🔌 中间件系统

中间件允许你在 RPC 请求前后执行自定义逻辑，如添加公共参数、认证信息、链路追踪等。

### 核心设计理念

> **用户自己在中间件的 `handle` 方法中通过 `$protocol` 对象来读取/写入参数**

### 使用方式

#### 1. 配置方式（推荐）

```php
// config/rpc.php
return [
    'middleware' => [
        \app\middleware\TraceMiddleware::class,
        \app\middleware\AuthMiddleware::class,
    ],
];
```

#### 2. 代码方式（带参数）

```php
use qs9000\rpc\middleware\InjectParamsMiddleware;
use qs9000\rpc\middleware\AuthMiddleware;

$client = app(SwooleRpcClient::class);

// 添加参数注入中间件
$client->middleware([InjectParamsMiddleware::class, [
    'app_id' => 'my_app',
    'version' => '1.0.0',
]]);

// 添加认证中间件
$client->middleware([AuthMiddleware::class, [
    env('RPC_AUTH_TOKEN', ''),
    'api_key'
]]);
```

#### 3. 闭包方式

```php
$client->middleware(function ($protocol, $next) {
    // 添加追踪 ID
    $params = $protocol->getParams();
    $params['trace_id'] = uniqid('trace_', true);
    $protocol->setParams($params);
    
    // 执行调用
    $result = $next($protocol);
    
    return $result;
});
```

### 自定义中间件

```php
<?php
namespace app\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\swoole\rpc\Protocol;

class CustomMiddleware implements MiddlewareInterface
{
    protected App $app;
    protected string $customParam;

    public function __construct(App $app, string $customParam = 'default')
    {
        $this->app = $app;
        $this->customParam = $customParam;
    }

    public function handle(Protocol $protocol, callable $next): mixed
    {
        // === 请求前处理 ===
        
        // 直接操作 Protocol 对象
        $params = $protocol->getParams();
        $params['custom_field'] = $this->customParam;
        $protocol->setParams($params);
        
        // 执行后续中间件或实际调用
        $result = $next($protocol);
        
        // === 响应后处理 ===
        
        return $result;
    }
}
```

### 内置中间件

#### InjectParamsMiddleware - 参数注入

自动为所有请求添加公共参数：

```php
$client->middleware([InjectParamsMiddleware::class, [
    'app_id' => 'my_app',
    'version' => '1.0.0',
    'caller_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]]);
```

#### AuthMiddleware - 认证中间件

自动添加认证 Token：

```php
// 指定 token 和字段名
$client->middleware([AuthMiddleware::class, [
    'secret_token_123',
    'api_key'
]]);

// 或从环境变量读取
$client->middleware([AuthMiddleware::class, ['', 'api_key']]);
```

### 常见应用场景

#### 链路追踪

```php
$client->middleware(function ($protocol, $next) {
    $params = $protocol->getParams();
    $params['trace_id'] = request()->header('X-Trace-Id') ?: uniqid();
    $params['span_id'] = uniqid('span_', true);
    $protocol->setParams($params);
    return $next($protocol);
});
```

#### 性能监控

```php
$client->middleware(function ($protocol, $next) {
    $start = microtime(true);
    
    try {
        $result = $next($protocol);
        
        $duration = (microtime(true) - $start) * 1000;
        if ($duration > 1000) {
            trace("慢查询: {$duration}ms", 'warning');
        }
        
        return $result;
    } catch (\Throwable $e) {
        $duration = (microtime(true) - $start) * 1000;
        trace("RPC 错误: {$e->getMessage()} ({$duration}ms)", 'error');
        throw $e;
    }
});
```

📖 **详细文档**：[MIDDLEWARE_USAGE.md](MIDDLEWARE_USAGE.md)  
⚡ **快速参考**：[MIDDLEWARE_QUICK_REFERENCE.md](MIDDLEWARE_QUICK_REFERENCE.md)

---

## ⚙️ 配置指南

### 完整配置示例

```php
<?php
// config/rpc.php
return [
    // 注册中心配置
    'registry' => [
        'host' => env('RPC_REGISTRY_HOST', '127.0.0.1'),
        'port' => (int) env('RPC_REGISTRY_PORT', 9500),
        'timeout' => (int) env('RPC_REGISTRY_TIMEOUT', 5000),
        'token' => env('RPC_REGISTRY_TOKEN', null),
    ],
    
    // 服务发现配置
    'discovery' => [
        'loadbalancer' => env('RPC_LOADBALANCER', 'random'),
        'cache_ttl' => (int) env('RPC_CACHE_TTL', 30),
        'enable_graceful_degradation' => true,
    ],
    
    // 熔断器配置
    'circuitbreaker' => [
        'failure_threshold' => 5,
        'success_threshold' => 3,
        'timeout' => 60,
    ],
    
    // RPC 调用配置
    'timeout' => (int) env('RPC_TIMEOUT', 5),
    'tries' => (int) env('RPC_RETRIES', 2),
    
    // 连接池配置
    'connection' => [
        'max_connections' => 20,
        'connect_timeout' => 1,
        'enable_connection_pool' => true,
    ],
    
    // 中间件配置
    'middleware' => [
        // \app\middleware\CustomMiddleware::class,
    ],
];
```

### 环境变量

所有配置项都支持通过 `.env` 文件覆盖，完整列表参考 [.env.example](.env.example)。

---

## 🎯 高级特性

### 自定义负载均衡策略

```php
use qs9000\rpc\loadbalancer\LoadBalancerInterface;
use qs9000\rpc\contract\ServiceInstanceInterface;

class CustomLoadBalancer implements LoadBalancerInterface
{
    public function select(array $instances): ?ServiceInstanceInterface
    {
        if (empty($instances)) {
            return null;
        }
        
        // 自定义选择逻辑
        return $instances[array_rand($instances)];
    }
}

// 注册并使用
$factory = new \qs9000\rpc\loadbalancer\LoadBalancerFactory();
$factory->register('custom', CustomLoadBalancer::class);
$discovery->setLoadBalancerStrategy('custom');
```

### 连接池管理

```php
// 获取连接池统计
$stats = $client->getPoolStats();
echo "总连接数: " . $stats['total_connections'];
echo "活跃连接: " . $stats['active_connections'];

// 关闭所有连接
$client->close();
```

### 批量服务发现

```bash
# .env
RPC_ENABLE_BATCH_DISCOVERY=true
RPC_BATCH_DISCOVERY_SIZE=10
```

自动批量获取多个服务的实例信息，减少网络往返。

---

## 🏆 最佳实践

### 生产环境配置

```bash
# .env.production
RPC_REGISTRY_HOST=registry.internal.example.com
RPC_LOADBALANCER=roundrobin
RPC_TIMEOUT=5
RPC_RETRIES=2
RPC_CACHE_TTL=60
RPC_MAX_CONNECTIONS=50
RPC_DEBUG=false
RPC_LOG_LEVEL=error
```

### 开发环境配置

```bash
# .env.development
RPC_REGISTRY_HOST=127.0.0.1
RPC_LOADBALANCER=random
RPC_TIMEOUT=10
RPC_RETRIES=3
RPC_CACHE_TTL=10
RPC_DEBUG=true
RPC_LOG_LEVEL=debug
```

### 错误处理最佳实践

```php
try {
    $result = $client->call('UserService', 'getUser', ['id' => $id]);
    return $this->success($result);
    
} catch (RpcResponseException $e) {
    // 业务错误，记录日志
    Log::warning("RPC 业务错误: " . $e->getMessage());
    return $this->error('请求处理失败');
    
} catch (RpcClientException $e) {
    // 网络错误，客户端会自动重试
    Log::error("RPC 网络错误: " . $e->getMessage());
    return $this->error('服务暂时不可用');
    
} catch (RpcException $e) {
    // 熔断器开启
    if ($e->getCode() === -32000) {
        Log::warning("服务熔断");
        return $this->error('服务繁忙，请稍后重试');
    }
    
    return $this->error('系统错误');
}
```

### 性能优化建议

- ✅ 启用连接池：减少连接建立开销
- ✅ 合理设置超时：避免过长等待
- ✅ 适当的重试次数：平衡可靠性和延迟
- ✅ 选择合适的负载均衡策略
- ✅ 启用调试日志（仅开发环境）

---

## ❓ 常见问题

### Q1: 如何排查连接超时问题？

**A:** 
1. 确认注册中心地址和端口正确
2. 检查防火墙是否阻止连接
3. 增加超时时间：`RPC_CONNECT_TIMEOUT=3000`
4. 启用调试日志：`RPC_DEBUG=true`

### Q2: 熔断器频繁触发怎么办？

**A:** 
1. 检查后端服务健康状况
2. 增加失败阈值：`RPC_CIRCUIT_FAILURE_THRESHOLD=10`
3. 增加超时时间或重试次数
4. 查看熔断日志

### Q3: 如何实现服务版本管理？

**A:** 调用时传入版本号：
```php
$result = $client->call('UserService', 'getUser', ['id' => 1], 'v2');
```

### Q4: 中间件如何传递参数？

**A:** 使用数组格式 `[类名, [参数]]`：
```php
$client->middleware([InjectParamsMiddleware::class, [
    'app_id' => 'my_app',
]]);
```

### Q5: 如何自定义中间件？

**A:** 实现 `MiddlewareInterface` 接口：
```php
class MyMiddleware implements MiddlewareInterface
{
    public function handle(Protocol $protocol, callable $next): mixed
    {
        // 操作 $protocol 对象
        return $next($protocol);
    }
}
```
---

## 🤝 贡献指南

欢迎贡献代码、报告问题或提出建议！

### 开发规范

- 遵循 PSR-12 编码规范
- 添加必要的注释和文档
- 确保代码可测试

### 报告问题

请在 [Issues](https://github.com/qs9000/think-swoole-rpc/issues) 中报告问题，并提供：
- 问题描述
- 复现步骤
- 预期行为
- 实际行为
- 环境信息

---

## 📄 许可证

本项目采用 MIT 许可证 - 详见 [LICENSE](LICENSE) 文件

---

## 👥 致谢

感谢以下开源项目的支持：
- [ThinkPHP](https://www.thinkphp.cn) - 优秀的 PHP 框架
- [Swoole](https://www.swoole.com) - 高性能协程引擎
- 所有贡献者和用户

---

## 📞 联系方式

- **项目主页**: [GitHub](https://github.com/qs9000/think-swoole-rpc)
- **问题反馈**: [Issues](https://github.com/qs9000/think-swoole-rpc/issues)
