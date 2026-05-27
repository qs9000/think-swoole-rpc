<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\App;
use think\facade\Log;

/**
 * 全局分布式熔断器（Circuit Breaker based on Redis）
 * 
 * 状态数据存储在 Redis 中，支持多节点共享熔断状态，实现全局熔断保护。
 * 
 * 状态机：
 * - CLOSED（关闭）：正常状态
 * - OPEN（开启）：熔断状态，拒绝所有请求
 * - HALF_OPEN（半开）：试探状态
 * 
 * 并发安全：关键操作使用 Lua 脚本保证原子性。
 *
 * @package qs9000\rpc
 */
class CircuitBreaker
{
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    protected const CACHE_PREFIX = 'circuit_breaker:';
    protected const SERVICES_SET_KEY = 'circuit_breaker:services';   // Redis Set 存储服务名列表

    private App $app;

    /** @var int 触发熔断的连续失败次数阈值 */
    protected int $failureThreshold = 5;

    /** @var int 半开状态下恢复所需的连续成功次数阈值 */
    protected int $successThreshold = 3;

    /** @var int 熔断超时时间（秒） */
    protected int $timeout = 60;

    protected object|null $redis;

    /** @var bool Redis 是否可用 */
    protected bool $redisAvailable = true;

    /** @var array 配置参数 */
    protected array $config;

    /**
     * 构造函数
     *
     * @param App $app
     * @param array $config 配置项，支持：
     *   - failure_threshold: 失败阈值，默认5
     *   - success_threshold: 成功阈值，默认3
     *   - timeout: 熔断超时秒数，默认60
     *   - redis: Redis 配置数组，同 think\cache\driver\Redis 的配置
     */
    public function __construct(App $app, array $config = [])
    {
        $this->app = $app;

        // 合并配置
        if (empty($config)) {
            $config = $this->app->config->get('rpc.client.circuitbreaker', []);
        }

        $this->failureThreshold = max(1, $config['failure_threshold'] ?? 5);
        $this->successThreshold = max(1, $config['success_threshold'] ?? 3);
        $this->timeout = max(1, $config['timeout'] ?? 60);
        $this->config = $config;
        $cache = $this->config['cache'] ?? 'file';
        if ($cache === 'file') {
            $this->redis = null;
            $this->redisAvailable = false;
        } else {
            $this->redis = $this->app->cache->store($cache)->handler();
        }
    }



    /**
     * 检查服务是否处于熔断状态
     *
     * @param string $serviceName
     * @return bool true=已熔断
     */
    public function isOpen(string $serviceName): bool
    {
        if (!$this->redisAvailable) {
            return false; // Redis 不可用时降级为允许所有请求
        }

        $state = $this->getState($serviceName);

        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset($serviceName)) {
                $this->setState($serviceName, self::STATE_HALF_OPEN);
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * 检查是否允许调用服务
     *
     * @param string $serviceName
     * @return bool
     */
    public function allowRequest(string $serviceName): bool
    {
        return !$this->isOpen($serviceName);
    }

    /**
     * 记录成功调用（原子操作 + Lua 脚本保证半开状态下计数与状态转换的原子性）
     *
     * @param string $serviceName
     */
    public function recordSuccess(string $serviceName): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            // 半开状态：使用 Lua 脚本原子地增加成功计数，并判断是否达到阈值
            $lua = <<<LUA
                local key = KEYS[1]
                local threshold = tonumber(ARGV[1])
                -- 增加成功计数
                local successes = redis.call('hincrby', key, 'successes', 1)
                local currentState = redis.call('hget', key, 'state')
                if currentState == 'half_open' and successes >= threshold then
                    -- 达到阈值，重置计数并切换到 closed
                    redis.call('hmset', key, 'state', 'closed', 'failures', 0, 'successes', 0)
                    -- 可选：记录状态变更日志
                    return 1
                end
                return 0
LUA;
            $result = $this->redis->eval($lua, [$this->getHashKey($serviceName), $this->successThreshold], 1);
            if ($result == 1) {
                Log::info("熔断器服务 {$serviceName} 恢复成功，状态从 HALF_OPEN 转为 CLOSED");
            }
        } elseif ($state === self::STATE_CLOSED) {
            // 关闭状态下的成功，重置失败计数
            $this->resetFailureCount($serviceName);
        }
        // OPEN 状态下不会调用 recordSuccess
    }

    /**
     * 记录失败调用（原子操作：半开状态下立即切回 OPEN，关闭状态下递增并判断阈值）
     *
     * @param string $serviceName
     */
    public function recordFailure(string $serviceName): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            // 半开状态失败：立即回到 OPEN，并记录开启时间
            $this->setState($serviceName, self::STATE_OPEN);
            $this->recordOpenTime($serviceName);
            Log::warning("熔断器服务 {$serviceName} 试探请求失败，状态回退到 OPEN");
        } elseif ($state === self::STATE_CLOSED) {
            // 使用 Lua 脚本原子地增加失败计数并判断是否达到阈值
            $lua = <<<LUA
                local key = KEYS[1]
                local threshold = tonumber(ARGV[1])
                local now = tonumber(ARGV[2])
                local failures = redis.call('hincrby', key, 'failures', 1)
                redis.call('hset', key, 'last_failure_time', now)
                if failures >= threshold then
                    -- 达到阈值，切换到 OPEN 并记录开启时间
                    redis.call('hmset', key, 'state', 'open', 'opened_time', now)
                    return 1
                end
                return 0
LUA;
            $result = $this->redis->eval($lua, [$this->getHashKey($serviceName), $this->failureThreshold, time()], 1);
            if ($result == 1) {
                Log::warning("熔断器服务 {$serviceName} 失败次数达到阈值 {$this->failureThreshold}，开启熔断");
            }
        }
        // OPEN 状态下不会调用 recordFailure
    }

    /**
     * 获取熔断器当前状态
     *
     * @param string $serviceName
     * @return string
     */
    public function getState(string $serviceName): string
    {
        if (!$this->redisAvailable) {
            return self::STATE_CLOSED;
        }

        $this->initService($serviceName);
        $state = $this->redis->hGet($this->getHashKey($serviceName), 'state');
        return $state ?: self::STATE_CLOSED;
    }

    /**
     * 获取服务熔断统计数据
     *
     * @param string $serviceName
     * @return array
     */
    public function getStats(string $serviceName): array
    {
        if (!$this->redisAvailable) {
            return [];
        }

        $this->initService($serviceName);
        $key = $this->getHashKey($serviceName);
        $data = $this->redis->hGetAll($key);

        return [
            'state' => $data['state'] ?? self::STATE_CLOSED,
            'failures' => (int)($data['failures'] ?? 0),
            'successes' => (int)($data['successes'] ?? 0),
            'last_failure_time' => (int)($data['last_failure_time'] ?? 0),
            'opened_time' => (int)($data['opened_time'] ?? 0),
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * 获取所有服务的熔断状态统计
     *
     * @return array
     */
    public function getAllStats(): array
    {
        if (!$this->redisAvailable) {
            return [];
        }

        $stats = [];
        $services = $this->getRegisteredServices();

        foreach ($services as $name) {
            $stats[$name] = $this->getStats($name);
        }

        return $stats;
    }

    /**
     * 重置指定服务的熔断器
     *
     * @param string $serviceName
     */
    public function reset(string $serviceName): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        $key = $this->getHashKey($serviceName);
        $this->redis->del($key);
        $this->initService($serviceName); // 重新初始化为关闭状态
        Log::info("熔断器服务 {$serviceName} 已手动重置");
    }

    /**
     * 重置所有服务的熔断器
     */
    public function resetAll(): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        $services = $this->getRegisteredServices();
        foreach ($services as $name) {
            $this->reset($name);
        }
        // 清空服务列表
        $this->redis->del(self::SERVICES_SET_KEY);
    }

    /**
     * 设置失败阈值
     *
     * @param int $threshold
     * @return self
     */
    public function setFailureThreshold(int $threshold): self
    {
        $this->failureThreshold = max(1, $threshold);
        return $this;
    }

    /**
     * 设置成功阈值
     *
     * @param int $threshold
     * @return self
     */
    public function setSuccessThreshold(int $threshold): self
    {
        $this->successThreshold = max(1, $threshold);
        return $this;
    }

    /**
     * 设置熔断超时时间
     *
     * @param int $timeout
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);
        return $this;
    }

    /**
     * 获取配置信息
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'timeout' => $this->timeout,
            'redis' => $this->config['redis'] ?? [],
        ];
    }

    /**
     * 获取健康的服务列表（未熔断的服务）
     *
     * @return array
     */
    public function getHealthyServices(): array
    {
        $healthy = [];
        $services = $this->getRegisteredServices();

        foreach ($services as $name) {
            $state = $this->getState($name);
            if ($state === self::STATE_CLOSED) {
                $healthy[] = $name;
            }
        }

        return $healthy;
    }

    /**
     * 获取熔断的服务列表
     *
     * @return array
     */
    public function getTrippedServices(): array
    {
        $tripped = [];
        $services = $this->getRegisteredServices();

        foreach ($services as $name) {
            $state = $this->getState($name);
            if ($state === self::STATE_OPEN) {
                $tripped[] = $name;
            }
        }

        return $tripped;
    }

    // ========== 私有辅助方法 ==========

    /**
     * 获取 Redis Hash 键名
     *
     * @param string $serviceName
     * @return string
     */
    protected function getHashKey(string $serviceName): string
    {
        return self::CACHE_PREFIX . $serviceName;
    }

    /**
     * 初始化服务数据（如果不存在则创建）
     *
     * @param string $serviceName
     */
    protected function initService(string $serviceName): void
    {
        $key = $this->getHashKey($serviceName);
        // 使用 setnx 保证原子初始化
        $initialized = $this->redis->setnx($key, '');
        if ($initialized) {
            // 设置 Hash 字段初始值
            $this->redis->hMSet($key, [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'successes' => 0,
                'last_failure_time' => 0,
                'opened_time' => 0,
            ]);
            // 设置键过期时间（可选，避免无用数据堆积，设为 timeout*2 但至少 10 分钟）
            $ttl = max($this->timeout * 2, 600);
            $this->redis->expire($key, $ttl);
            // 注册服务到全局集合
            $this->registerService($serviceName);
        }
    }

    /**
     * 设置状态
     *
     * @param string $serviceName
     * @param string $state
     */
    protected function setState(string $serviceName, string $state): void
    {
        $key = $this->getHashKey($serviceName);
        $oldState = $this->redis->hGet($key, 'state');
        if ($oldState !== $state) {
            $this->redis->hSet($key, 'state', $state);
            // 可选记录日志
        }
    }

    /**
     * 重置失败计数（成功时调用）
     *
     * @param string $serviceName
     */
    protected function resetFailureCount(string $serviceName): void
    {
        $key = $this->getHashKey($serviceName);
        $this->redis->hSet($key, 'failures', 0);
    }

    /**
     * 记录熔断开启时间
     *
     * @param string $serviceName
     */
    protected function recordOpenTime(string $serviceName): void
    {
        $key = $this->getHashKey($serviceName);
        $this->redis->hSet($key, 'opened_time', time());
    }

    /**
     * 检查是否应该尝试重置（从 OPEN 到 HALF_OPEN）
     *
     * @param string $serviceName
     * @return bool
     */
    protected function shouldAttemptReset(string $serviceName): bool
    {
        $key = $this->getHashKey($serviceName);
        $openedTime = (int)$this->redis->hGet($key, 'opened_time');
        if ($openedTime <= 0) {
            return true; // 没有开启时间记录，允许尝试（理论上不会发生）
        }
        return (time() - $openedTime) >= $this->timeout;
    }

    /**
     * 注册服务到全局 Set
     *
     * @param string $serviceName
     */
    protected function registerService(string $serviceName): void
    {
        $this->redis->sAdd(self::SERVICES_SET_KEY, $serviceName);
        // 服务列表永不过期，但手动清理由 resetAll 提供
    }

    /**
     * 获取已注册的所有服务名
     *
     * @return array
     */
    protected function getRegisteredServices(): array
    {
        $services = $this->redis->sMembers(self::SERVICES_SET_KEY);
        return is_array($services) ? $services : [];
    }
}
