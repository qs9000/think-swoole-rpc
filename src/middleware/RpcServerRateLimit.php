<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\facade\Log;
use think\swoole\rpc\Protocol;

// 优化点 1: 修正类名拼写错误 RateLimt -> RateLimit
class RpcServerRateLimit implements MiddlewareInterface
{
    private App $app;

    // 优化点 2: 提取常量，提高可维护性
    private const DEFAULT_CACHE_STORE = 'file';
    private const DEFAULT_LIMIT = 100;
    private const DEFAULT_INTERVAL = 60;
    private const CACHE_KEY_PREFIX = 'rpc_rate_limit';

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Protocol $protocol, \Closure $next): mixed
    {
        $config = $this->app->config->get('rpc.server.rate_limit');

        // 边界条件检查：确保配置是数组且启用
        if (!is_array($config) || !($config['enable'] ?? false)) {
            return $next($protocol);
        }

        // 检查自定义限流类
        if (isset($config['limit_class'])) {
            $rateLimitClass = $config['limit_class'];

            // 健壮性检查：确保类名有效且存在
            if (!is_string($rateLimitClass) || !class_exists($rateLimitClass)) {
                throw new \qs9000\rpc\RpcException('请配置正确的限流类');
            }

            $rateLimitInstance = new $rateLimitClass;

            // 健壮性检查：确保实现了必要的方法
            if (!method_exists($rateLimitInstance, 'handle')) {
                throw new \qs9000\rpc\RpcException('自定义限流类必须实现 handle 方法');
            }

            $result = $rateLimitInstance->handle($protocol);

            // 确保传递给下一个中间件的是 Protocol 对象
            return $next($result instanceof Protocol ? $result : $protocol);
        }

        // 执行默认限流逻辑
        if (!$this->rateLimit($protocol, $config)) {
            throw new \qs9000\rpc\RpcException('请求频率过高，请稍后再试');
        }

        return $next($protocol);
    }

    protected function rateLimit(Protocol $protocol, array $config): bool
    {
        // 1. 获取配置，提供默认值并进行有效性校验
        $storeName = $config['cache'] ?? self::DEFAULT_CACHE_STORE;

        // 确保 limit 和 interval 为正整数，防止逻辑错误
        $limit = (int) ($config['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $interval = (int) ($config['interval'] ?? self::DEFAULT_INTERVAL);
        if ($interval <= 0) {
            $interval = self::DEFAULT_INTERVAL;
        }

        // 2. 构建安全的缓存 Key
        $context = $protocol->getContext();
        $serverName = $context['server_name'] ?? 'unknown';
        $method = $protocol->getMethod() ?? 'unknown';
        $currentServer = $this->app->config->get('app.name', 'unknown');
        // 使用 md5 确保 Key 长度固定且无特殊字符，适合所有缓存驱动
        $key = md5(self::CACHE_KEY_PREFIX . ":{$currentServer}:{$serverName}:{$method}");

        try {
            // 3. 获取缓存实例
            $cache = $this->app->cache->store($storeName);

            // 4. 原子递增计数
            // 注意：ThinkPHP 的 inc 在 Key 不存在时会创建 Key 并设为 1，但通常不设置过期时间
            $count = $cache->inc($key);

            // 5. 如果是第一次请求（count 为 1），设置过期时间
            // 这一步至关重要，否则 Key 将永久存在，导致限流永久生效或内存泄漏
            if ($count === 1) {
                $cache->set($key, 1, $interval);
            }

            // 6. 判断是否超限
            if ($count > $limit) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Rpc服务端限流错误：" . $e->getMessage(), $e->getTrace());
            return true;
        }
    }
}
