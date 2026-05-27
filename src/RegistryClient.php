<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\facade\Config;
use qs9000\rpc\registry\RegistryClientInterface;
use Swoole\Coroutine;

/**
 * 注册中心客户端
 * 
 * 负责与注册中心的所有通信，提供：
 * - 服务注册/注销
 * - 服务发现
 * - 心跳保活
 * - 健康检查
 * 
 * 隔离网络通信细节，解耦 SDK 与注册中心实现
 *
 * @package qs9000\rpc
 */
class RegistryClient implements RegistryClientInterface
{
    private string $cacheKeyPrefix;
    private string $cacheStore;
    private int $heartbeatInterval;
    private string $type;
    private mixed $cacheInstance;

    /**
     *@inheritDoc
     */
    public function __construct(string $type = 'rpc')
    {
        $this->type = $type;
        $this->cacheKeyPrefix = match ($type) {
            'rpc' => 'registry:rpc:',
            'server' => 'registry:server:',
            default => throw new \Exception('非法的注册类型'),
        };
        $this->cacheStore = Config::get('rpc.registry.cache');
        $this->heartbeatInterval = Config::get("rpc.registry.{$type}.heartbeat_interval", 30);

        // 初始化缓存实例
        $this->cacheInstance = app()->cache->store($this->cacheStore);
    }

    /**
     * 执行Redis操作的协程安全包装器
     * 
     * 使用协程锁确保同一时间只有一个协程操作Redis连接
     */
    private function executeSafely(callable $operation)
    {
        // 检查是否在Swoole协程环境中
        if (extension_loaded('swoole') && Coroutine::getCid() > 0) {
            // 在协程环境中，使用锁确保Redis连接安全
            static $lock;
            if (!$lock) {
                $lock = new \Swoole\Coroutine\Lock();
            }

            $lock->lock();
            try {
                return $operation($this->cacheInstance);
            } catch (\Throwable $e) {
                // 捕获Redis连接相关错误
                \think\facade\Log::warning("[Registry] 缓存操作失败: " . $e->getMessage());
                
                // 如果是Redis连接问题，尝试重新初始化缓存实例
                if (strpos($e->getMessage(), 'Redis server went away') !== false || 
                    strpos($e->getMessage(), 'Connection closed') !== false ||
                    strpos($e->getMessage(), 'Connection lost') !== false) {
                    try {
                        $this->cacheInstance = app()->cache->store($this->cacheStore);
                        // 重试操作
                        return $operation($this->cacheInstance);
                    } catch (\Throwable $retryException) {
                        \think\facade\Log::error("[Registry] 重试缓存操作失败: " . $retryException->getMessage());
                        return false;
                    }
                }
                return false;
            } finally {
                $lock->unlock();
            }
        } else {
            // 非协程环境，直接执行
            try {
                return $operation($this->cacheInstance);
            } catch (\Throwable $e) {
                // 捕获Redis连接相关错误
                \think\facade\Log::warning("[Registry] 缓存操作失败: " . $e->getMessage());
                
                // 如果是Redis连接问题，尝试重新初始化缓存实例
                if (strpos($e->getMessage(), 'Redis server went away') !== false || 
                    strpos($e->getMessage(), 'Connection closed') !== false ||
                    strpos($e->getMessage(), 'Connection lost') !== false) {
                    try {
                        $this->cacheInstance = app()->cache->store($this->cacheStore);
                        // 重试操作
                        return $operation($this->cacheInstance);
                    } catch (\Throwable $retryException) {
                        \think\facade\Log::error("[Registry] 重试缓存操作失败: " . $retryException->getMessage());
                        return false;
                    }
                }
                return false;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function register(array $data): bool
    {
        return $this->executeSafely(function ($cache) use ($data) {
            $name = $data['name'] ?? '';
            $host = $data['host'] ?? '';
            $port = $data['port'] ?? '';
            $key = "{$this->cacheKeyPrefix}{$name}:{$host}:{$port}";
            $time = time();
            $data['registered_at'] = $time;
            $data['last_heartbeat'] = $time;

            $lastData = $cache->get($key);
            if (!empty($lastData)) {
                $data['last_registered_at'] = $lastData['registered_at'];
            } else {
                $data['last_registered_at'] = $time;
            }
            return $cache->set($key, $data, $this->heartbeatInterval * 2);
        });
    }

    /**
     * @inheritDoc
     */
    public function unregister(string $key): bool
    {
        return $this->executeSafely(function ($cache) use ($key) {
            $key = "{$this->cacheKeyPrefix}{$key}";
            if ($cache->has($key)) {
                return $cache->delete($key);
            }
            return true;
        });
    }

    /**
     * @inheritDoc
     */
    public function heartbeat(string $key): bool
    {
        return $this->executeSafely(function ($cache) use ($key) {
            $key = "{$this->cacheKeyPrefix}{$key}";
            $lastData = $cache->get($key);
            if ($lastData) {
                $lastData['last_heartbeat'] = time();
                return $cache->set($key, $lastData, $this->heartbeatInterval * 2);
            }
            return false;
        });
    }

    /**
     * @inheritDoc
     */
    public function health(string $key): bool
    {
        return $this->executeSafely(function ($cache) use ($key) {
            $key = "{$this->cacheKeyPrefix}{$key}";
            $lastData = $cache->get($key);
            if ($lastData) {
                return time() - $lastData['last_heartbeat'] <= $this->heartbeatInterval * 2;
            }
            return false;
        });
    }

    /**
     * @inheritDoc
     */
    public function discover(string $name): array
    {
        $pattern = "{$this->cacheKeyPrefix}{$name}:*";
        $datas = [];
        $now = time();

        $this->redisScan($pattern, function ($key) use (&$datas, $now) {
            $this->executeSafely(function ($cache) use ($key, &$datas, $now) {
                $content = $cache->get($key);
                if (is_array($content) && !empty($content)) {
                    $content['health'] = $now - $content['last_heartbeat'] <= $this->heartbeatInterval * 2 ? true : false;
                    $datas[] = $content;
                }
            });
        });

        return $datas;
    }

    private function redisScan(string $pattern = '*', ?callable $callback = null, int $count = 100): void
    {
        // 验证并修正计数参数，确保其为有效正值
        if ($count <= 0) {
            $count = 100; // 使用默认值
        }

        $this->executeSafely(function ($cache) use ($pattern, $callback, $count) {
            $redis = $cache->handler();
            $iterator = null;
            // 循环执行 SCAN 命令直到遍历完成
            while (true) {
                $result = $redis->scan($iterator, $pattern, $count);

                // 检查结果是否为有效数组，若无效则终止循环
                if ($result === false || !is_array($result)) {
                    break;
                }

                // 如果提供了回调函数，则执行它
                if ($callback !== null) {
                    $callback($result);
                }

                // 当迭代器变为0时，扫描完成
                if ($iterator === 0 || $iterator === '0') {
                    break;
                }
            }
        });
    }
}
