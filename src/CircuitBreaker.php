<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\facade\Log;

/**
 * 熔断器（Circuit Breaker）
 * 
 * 防止级联故障，在服务连续失败时自动开启熔断保护
 * 
 * 状态机：
 * - CLOSED（关闭）：正常状态，允许所有请求通过
 * - OPEN（开启）：熔断状态，拒绝所有请求，快速失败
 * - HALF_OPEN（半开）：试探状态，允许一个请求测试服务是否恢复
 * 
 * 状态转换：
 * CLOSED → OPEN：连续失败次数 >= failureThreshold
 * OPEN → HALF_OPEN：熔断超时时间到达
 * HALF_OPEN → CLOSED：连续成功次数 >= successThreshold
 * HALF_OPEN → OPEN：试探请求失败
 *
 * @package qs9000\rpc
 */
class CircuitBreaker
{
    // 熔断器状态常量
    const STATE_CLOSED = 'closed';       // 关闭，正常调用
    const STATE_OPEN = 'open';           // 开启，拒绝调用
    const STATE_HALF_OPEN = 'half_open'; // 半开，允许试探请求

    /** @var string 缓存键前缀 */
    protected const CACHE_PREFIX = 'circuit_breaker:';

    /** @var string 服务列表缓存键 */
    protected const SERVICES_LIST_KEY = 'circuit_breaker:_services_list';

    /** @var int 触发熔断的连续失败次数阈值 */
    protected int $failureThreshold = 5;

    /** @var int 半开状态下恢复所需的连续成功次数阈值 */
    protected int $successThreshold = 3;

    /** @var int 熔断超时时间（秒），之后进入半开状态 */
    protected int $timeout = 60;

    /** @var int 请求超时时间（毫秒）- 保留用于兼容性 */
    protected int $requestTimeout = 5000;

    /** @var mixed 缓存实例 */
    protected $cache;

    public function __construct(array $config = [])
    {
        // 从配置加载参数
        if (empty($config) && function_exists('config')) {
            $config = config('rpc.client.circuitbreaker', []);
        }

        $this->failureThreshold = max(1, $config['failure_threshold'] ?? 5);
        $this->successThreshold = max(1, $config['success_threshold'] ?? 3);
        $this->timeout = max(1, $config['timeout'] ?? 60);
        $this->requestTimeout = max(100, $config['request_timeout'] ?? 5000);

        try {
            if (function_exists('app')) {
                $this->cache = app()->cache->store('file');
            } else {
                throw new \RuntimeException('Cache store not available');
            }
        } catch (\Throwable $e) {
            Log::error('熔断器缓存初始化失败：'.$e->getMessage(),$e->getTrace());
            $this->cache = null;
        }
    }

    /**
     * 检查服务是否处于熔断状态
     *
     * @param string $serviceName 服务名称
     * @return bool true=已熔断，false=未熔断
     */
    public function isOpen(string $serviceName): bool
    {
        $state = $this->getState($serviceName);

        if ($state === self::STATE_OPEN) {
            // 检查是否超时，可以进入半开状态
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
     * @param string $serviceName 服务名称
     * @return bool true=允许调用，false=拒绝调用
     */
    public function allowRequest(string $serviceName): bool
    {
        return !$this->isOpen($serviceName);
    }

    /**
     * 记录成功调用
     *
     * @param string $serviceName 服务名称
     */
    public function recordSuccess(string $serviceName): void
    {
        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            // 半开状态下的成功
            $successes = $this->incrementSuccess($serviceName);

            if ($successes >= $this->successThreshold) {
                // 达到成功阈值，恢复到关闭状态
                $this->setState($serviceName, self::STATE_CLOSED);
                $this->resetCounters($serviceName);
            }
        } elseif ($state === self::STATE_CLOSED) {
            // 关闭状态下的成功，重置失败计数（而不是递减）
            // 这样可以在连续成功后快速恢复，但不会因为偶尔的成功而掩盖问题
            $this->resetFailureCount($serviceName);
        }
        // OPEN 状态下不会调用此方法
    }

    /**
     * 记录失败调用
     *
     * @param string $serviceName 服务名称
     */
    public function recordFailure(string $serviceName): void
    {
        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            // 半开状态下的失败，立即回到开启状态
            $this->setState($serviceName, self::STATE_OPEN);
            $this->recordOpenTime($serviceName);
        } elseif ($state === self::STATE_CLOSED) {
            // 关闭状态下的失败
            $failures = $this->incrementFailure($serviceName);

            if ($failures >= $this->failureThreshold) {
                // 达到失败阈值，开启熔断
                $this->setState($serviceName, self::STATE_OPEN);
                $this->recordOpenTime($serviceName);
            }
        }
        // OPEN 状态下不会调用此方法
    }

    /**
     * 获取熔断器当前状态
     *
     * @param string $serviceName 服务名称
     * @return string STATE_CLOSED | STATE_OPEN | STATE_HALF_OPEN
     */
    public function getState(string $serviceName): string
    {
        $this->initService($serviceName);
        $data = $this->cacheGet($serviceName);
        return $data['state'] ?? self::STATE_CLOSED;
    }

    /**
     * 获取服务熔断统计数据
     *
     * @param string $serviceName 服务名称
     * @return array 统计数据
     */
    public function getStats(string $serviceName): array
    {
        $this->initService($serviceName);
        $service = $this->cacheGet($serviceName);

        return [
            'state' => $service['state'] ?? self::STATE_CLOSED,
            'failures' => $service['failures'] ?? 0,
            'successes' => $service['successes'] ?? 0,
            'last_failure_time' => $service['last_failure_time'] ?? 0,
            'opened_time' => $service['opened_time'] ?? 0,
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * 获取所有服务的熔断状态统计
     *
     * @return array [serviceName => stats]
     */
    public function getAllStats(): array
    {
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
     * @param string $serviceName 服务名称
     */
    public function reset(string $serviceName): void
    {
        $this->resetCounters($serviceName);
        $this->setState($serviceName, self::STATE_CLOSED);
    }

    /**
     * 重置所有服务的熔断器
     */
    public function resetAll(): void
    {
        $services = $this->getRegisteredServices();
        foreach ($services as $name) {
            $this->reset($name);
        }
    }

    /**
     * 设置失败阈值
     *
     * @param int $threshold 失败阈值
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
     * @param int $threshold 成功阈值
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
     * @param int $timeout 超时时间（秒）
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);
        return $this;
    }

    /**
     * 初始化服务数据
     *
     * @param string $serviceName 服务名称
     */
    protected function initService(string $serviceName): void
    {
        $cacheKey = $this->getCacheKey($serviceName);

        // 检查服务是否已存在，如果不存在则初始化
        if (!$this->cacheHas($serviceName)) {
            $service = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'successes' => 0,
                'last_failure_time' => 0,
                'opened_time' => 0,
            ];
            // 初始 TTL 设为较大值，避免在熔断期间缓存过期导致状态丢失
            $this->cacheSet($serviceName, $service, $this->getCacheTtl());

            // 注册服务到列表
            $this->registerService($serviceName);
        }
    }

    /**
     * 设置状态
     *
     * @param string $serviceName 服务名称
     * @param string $state 新状态
     */
    protected function setState(string $serviceName, string $state): void
    {
        $this->initService($serviceName);

        $service = $this->cacheGet($serviceName);
        // 状态变更日志（可用于监控）
        if (($service['state'] ?? '') !== $state) {
            $service['state'] = $state;
            $this->cacheSet($serviceName, $service, $this->getCacheTtl());
        }
    }

    /**
     * 增加失败计数
     *
     * @param string $serviceName 服务名称
     * @return int 更新后的失败计数
     */
    protected function incrementFailure(string $serviceName): int
    {
        $this->initService($serviceName);

        $service = $this->cacheGet($serviceName);
        $service['failures'] = ($service['failures'] ?? 0) + 1;
        $service['last_failure_time'] = time();

        $this->cacheSet($serviceName, $service, $this->getCacheTtl());

        return $service['failures'];
    }

    /**
     * 重置失败计数（成功后调用）
     *
     * @param string $serviceName 服务名称
     */
    protected function resetFailureCount(string $serviceName): void
    {
        $this->initService($serviceName);

        $service = $this->cacheGet($serviceName);
        if (isset($service['failures'])) {
            $service['failures'] = 0;
            $this->cacheSet($serviceName, $service, $this->getCacheTtl());
        }
    }

    /**
     * 减少失败计数（已废弃，保留用于向后兼容）
     *
     * @deprecated 使用 resetFailureCount 代替
     * @param string $serviceName 服务名称
     */
    protected function decrementFailure(string $serviceName): void
    {
        // 保留此方法以兼容旧代码，但不再使用
        $this->resetFailureCount($serviceName);
    }

    /**
     * 增加成功计数
     *
     * @param string $serviceName 服务名称
     * @return int 更新后的成功计数
     */
    protected function incrementSuccess(string $serviceName): int
    {
        $this->initService($serviceName);

        $service = $this->cacheGet($serviceName);
        $success = $service['successes'] ?? 0;
        $service['successes'] = $success + 1;

        $this->cacheSet($serviceName, $service, $this->getCacheTtl());

        return $service['successes'];
    }

    /**
     * 重置计数器
     *
     * @param string $serviceName 服务名称
     */
    protected function resetCounters(string $serviceName): void
    {
        $this->initService($serviceName);

        $service = $this->cacheGet($serviceName);
        if (!empty($service)) {
            $service['failures'] = 0;
            $service['successes'] = 0;
            $this->cacheSet($serviceName, $service, $this->getCacheTtl());
        }
    }

    /**
     * 记录熔断开启时间
     *
     * @param string $serviceName 服务名称
     */
    protected function recordOpenTime(string $serviceName): void
    {
        $this->initService($serviceName);

        $service = $this->cacheGet($serviceName);
        $service['opened_time'] = time();

        $this->cacheSet($serviceName, $service, $this->getCacheTtl());
    }

    /**
     * 检查是否应该尝试重置（从 OPEN 到 HALF_OPEN）
     *
     * @param string $serviceName 服务名称
     * @return bool true=应该尝试重置
     */
    protected function shouldAttemptReset(string $serviceName): bool
    {
        $service = $this->cacheGet($serviceName);
        if (!isset($service['opened_time']) || $service['opened_time'] === 0) {
            return true;
        }
        $elapsed = time() - $service['opened_time'];
        return $elapsed >= $this->timeout;
    }

    /**
     * 获取熔断器配置信息
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'timeout' => $this->timeout,
            'request_timeout' => $this->requestTimeout,
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

    /**
     * 生成统一的缓存键
     *
     * @param string $serviceName
     * @return string
     */
    protected function getCacheKey(string $serviceName): string
    {
        return self::CACHE_PREFIX . $serviceName;
    }

    /**
     * 计算缓存 TTL
     *
     * @return int
     */
    protected function getCacheTtl(): int
    {
        // TTL 应至少大于熔断超时时间的两倍，防止在熔断期间缓存过期
        return max($this->timeout * 2, 300);
    }

    /**
     * 封装缓存获取，增加容错
     *
     * @param string $serviceName
     * @return array
     */
    protected function cacheGet(string $serviceName): array
    {
        if (!$this->cache) {
            return [];
        }
        try {
            $key = $this->getCacheKey($serviceName);
            $data = $this->cache->get($key);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::error('熔断器缓存获取失败：' . $e->getMessage(), $e->getTrace());
            return [];
        }
    }

    /**
     * 封装缓存设置，增加容错
     *
     * @param string $serviceName
     * @param array $data
     * @param int $ttl
     * @return void
     */
    protected function cacheSet(string $serviceName, array $data, int $ttl): void
    {
        if (!$this->cache) {
            return;
        }
        try {
            $key = $this->getCacheKey($serviceName);
            $this->cache->set($key, $data, $ttl);
        } catch (\Throwable $e) {
            Log::error('熔断器缓存设置失败：'.$e->getMessage(),$e->getTrace());
        }
    }

    /**
     * 检查缓存是否存在
     *
     * @param string $serviceName
     * @return bool
     */
    protected function cacheHas(string $serviceName): bool
    {
        if (!$this->cache) {
            return false;
        }
        try {
            $key = $this->getCacheKey($serviceName);
            return $this->cache->has($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 注册服务到全局列表
     *
     * @param string $serviceName
     * @return void
     */
    protected function registerService(string $serviceName): void
    {
        if (!$this->cache) {
            return;
        }
        try {
            $list = $this->cache->get(self::SERVICES_LIST_KEY, []);
            if (!is_array($list)) {
                $list = [];
            }

            if (!in_array($serviceName, $list, true)) {
                $list[] = $serviceName;
                // 服务列表长期有效，除非手动清理
                $this->cache->set(self::SERVICES_LIST_KEY, $list, 86400 * 30);
            }
        } catch (\Throwable $e) {
            Log::error('熔断器注册服务失败：' . $e->getMessage(), $e->getTrace());
        }
    }

    /**
     * 获取已注册的服务列表
     *
     * @return array
     */
    protected function getRegisteredServices(): array
    {
        if (!$this->cache) {
            return [];
        }
        try {
            $list = $this->cache->get(self::SERVICES_LIST_KEY, []);
            return is_array($list) ? $list : [];
        } catch (\Throwable $e) {
            Log::error('熔断器获取注册服务失败：' . $e->getMessage(), $e->getTrace());
            return [];
        }
    }
}
