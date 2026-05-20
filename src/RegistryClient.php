<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\registry\RegistryHttpClient;
use qs9000\rpc\registry\RegistryRpcClient;
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
    private RegistryClientInterface $client;

    public function __construct(string $type)
    {
        $config = App('config')->get('rpc.registry');
        if (empty($config) || !$config[$type]['enable']) {
            return;
        }
        $config = array_merge($config, $config[$type]);
        $this->client = match ($config[$type]['method'] ?? 'rpc') {
            'http' => new RegistryHttpClient($config),
            'rpc' => new RegistryRpcClient(),
            default => throw new \InvalidArgumentException('不支持的注册中心访问方法: ' . ($config[$type]['method'] ?? 'null')),
        };
    }

    /**
     * @inheritDoc
     */
    public function register(string $type, array $data): bool
    {
        return $this->client->register($type, $data);
    }

    /**
     * @inheritDoc
     */
    public function unregister(string $type, string $serviceName): bool
    {
        return $this->client->unregister($type, $serviceName);
    }

    /**
     * @inheritDoc
     */
    public function heartbeat(string $type, string $serviceName): bool
    {
        return $this->client->heartbeat($type, $serviceName);
    }

    /**
     * @inheritDoc
     */
    public function health(string $type, string $serviceName): bool
    {
        return $this->client->health($type, $serviceName);
    }

    /**
     * @inheritDoc
     */
    public function discover(string $type, string $serviceName): array
    {
        return $this->client->discover($type, $serviceName);
    }

    /**
     * @inheritDoc
     */
    public function list(string $type, string $serviceName = ''): array
    {
        return $this->client->list($type, $serviceName);
    }

    /**
     * @inheritDoc
     */
    public function listHost(string $type, string $host = '*', string $port = '*'): array
    {
        return $this->client->listHost($type, $host, $port);
    }
}
