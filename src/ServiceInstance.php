<?php

declare(strict_types=1);

namespace qs9000\rpc;

use qs9000\rpc\contract\ServiceInstanceInterface;

/**
 * 服务实例
 *
 * 表示一个 RPC 服务实例的信息
 */
class ServiceInstance implements ServiceInstanceInterface
{
    protected string $id;
    protected string $name;
    protected string $host;
    protected int $port;
    protected bool $healthy = true;
    protected int $weight = 100;
    protected array $metadata = [];
    protected int $registeredAt;
    protected int $lastHeartbeat;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? $this->generateId($data);
        $this->name = $data['name'] ?? '';
        $this->host = $data['host'] ?? '127.0.0.1';
        $this->port = (int) ($data['port'] ?? 9501);
        $this->healthy = (bool) ($data['healthy'] ?? $data['health'] ?? true);
        $this->weight = (int) ($data['weight'] ?? 100);
        $this->metadata = $data['metadata'] ?? [];
        $this->registeredAt = (int) ($data['registered_at'] ?? time());
        $this->lastHeartbeat = (int) ($data['last_heartbeat'] ?? time());
    }

    /**
     * 获取实例 ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取服务名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取主机地址
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * 获取端口
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * 获取完整地址 (host:port)
     */
    public function getAddress(): string
    {
        return "{$this->host}:{$this->port}";
    }

    /**
     * 是否健康
     */
    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    /**
     * 设置健康状态
     */
    public function setHealthy(bool $healthy): self
    {
        $this->healthy = $healthy;
        return $this;
    }

    /**
     * 获取权重
     */
    public function getWeight(): int
    {
        return $this->weight;
    }

    /**
     * 获取元数据
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 获取元数据项
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 获取注册时间
     */
    public function getRegisteredAt(): int
    {
        return $this->registeredAt;
    }

    /**
     * 获取最后心跳时间
     */
    public function getLastHeartbeat(): int
    {
        return $this->lastHeartbeat;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'healthy' => $this->healthy,
            'weight' => $this->weight,
            'metadata' => $this->metadata,
            'registered_at' => $this->registeredAt,
            'last_heartbeat' => $this->lastHeartbeat,
        ];
    }

    /**
     * 生成实例 ID
     */
    protected function generateId(array $data): string
    {
        $name = $data['name'] ?? '';
        $host = $data['host'] ?? '127.0.0.1';
        $port = $data['port'] ?? 9501;

        return md5("{$name}:{$host}:{$port}:" . uniqid());
    }

    /**
     * 从数组创建实例
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}
