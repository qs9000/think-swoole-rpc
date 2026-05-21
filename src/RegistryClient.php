<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\registry\RegistryHttpClient;
use qs9000\rpc\registry\RegistryRpcClient;

use think\facade\Config;

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
class RegistryClient
{
    private RegistryHttpClient|RegistryRpcClient $client;

    public function __construct(string $type)
    {
        $config = Config::get('rpc.registry.' . $type);
        if (empty($config) || !$config['enable']) {
            return;
        }
        $this->client = match ($config['method'] ?? 'rpc') {
            'http' => new RegistryHttpClient($type),
            'rpc' => new RegistryRpcClient($type),
            default => throw new \InvalidArgumentException('不支持的注册中心访问方法: ' . ($config[$type]['method'] ?? 'null')),
        };
    }

    /**
     * @inheritDoc
     */
    public function register(array $data): bool
    {
        return $this->client->register($data);
    }

    /**
     * @inheritDoc
     */
    public function unregister(string $serviceName): bool
    {
        return $this->client->unregister($serviceName);
    }

    /**
     * @inheritDoc
     */
    public function heartbeat(string $serviceName): bool
    {
        return $this->client->heartbeat($serviceName);
    }

    /**
     * @inheritDoc
     */
    public function health(string $serviceName): bool
    {
        return $this->client->health($serviceName);
    }

    /**
     * @inheritDoc
     */
    public function discover(string $serviceName): array
    {
        return $this->client->discover($serviceName);
    }

    /**
     * @inheritDoc
     */
    public function list(string $serviceName = ''): array
    {
        return $this->client->list($serviceName);
    }

    /**
     * @inheritDoc
     */
    public function listHost(string $host = '*', string $port = '*'): array
    {
        return $this->client->listHost($host, $port);
    }
}
