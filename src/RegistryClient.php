<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\cache\driver\Redis;
use think\facade\Cache;
use think\facade\Config;
use qs9000\rpc\registry\RegistryClientInterface;

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
    private Redis $cache;
    private string $cacheKeyPrefix;

    private int $heartbeatInterval;

    /**
     *@inheritDoc
     */
    public function __construct(string $type = 'rpc')
    {
        $this->cacheKeyPrefix = match ($type) {
            'rpc' => 'registry:rpc:',
            'server' => 'registry:server:',
            default => throw new \Exception('非法的注册类型'),
        };
        $cache = Config::get('rpc.registry.cache');
        $this->cache = Cache::store($cache);
        if (!$this->cache instanceof Redis) {
            throw new \Exception('注册中心必须是Redis缓存');
        }
        $this->heartbeatInterval = Config::get("rpc.registry.{$type}.heartbeat_interval", 30);
    }

    /**
     * @inheritDoc
     */
    public function register(array $data): bool
    {
        $name= $data['name']??'';
        $host= $data['host']??'';
        $port= $data['port']??'';
        $key = "{$this->cacheKeyPrefix}:{$name}:{$host}:{$port}";
        $time = time();
        $data['registered_at'] = $time;
        $data['last_heartbeat'] = $time;
        $lastData = $this->cache->get($key);
        if (!empty($lastData)) {
            $data['last_registered_at'] = $lastData['registered_at'];
        } else {
            $data['last_registered_at'] = $time;
        }
        return $this->cache->set($key, $data, $this->heartbeatInterval * 2);
    }

    /**
     * @inheritDoc
     */
    public function unregister(string $key): bool
    {
        $key = "{$this->cacheKeyPrefix}:{$key}";
        if ($this->cache->has($key)) {
            return $this->cache->delete($key);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function heartbeat(string $key): bool
    {
        $key = "{$this->cacheKeyPrefix}:{$key}";
        $lastData = $this->cache->get($key);
        if ($lastData) {
            $lastData['last_heartbeat'] = time();
            return $this->cache->set($key, $lastData, $this->heartbeatInterval * 2);
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function health(string $key): bool
    {
        $key = "{$this->cacheKeyPrefix}:{$key}";
        $lastData = $this->cache->get($key);
        if ($lastData) {
            return time() - $lastData['last_heartbeat'] <= $this->heartbeatInterval * 2;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function discover(string $name): array
    {
        $patten = "{$this->cacheKeyPrefix}:{$name}:*";
        $datas = [];
        $now = time();
        $this->redisScan($patten, function ($key) use (&$datas, $now) {
            $content = $this->cache->get($key);
            if (is_array($content) && !empty($content)) {
                $content['health'] = $now - $content['last_heartbeat'] <= $this->heartbeatInterval * 2 ? true : false;
                $datas[] = $content;
            }
        });
        return $datas;
    }


    private function redisScan(string $pattern = '*', ?callable $callback = null, int $count = 100): void
    {
        // 验证并修正计数参数，确保其为有效正值
        if ($count <= 0) {
            $count = 100; // 使用默认值
        }
        $redis = $this->cache->handler();
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
    }
}
