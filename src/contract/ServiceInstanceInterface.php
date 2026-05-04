<?php

declare(strict_types=1);

namespace qs9000\rpc\contract;

/**
 * 服务实例接口
 */
interface ServiceInstanceInterface
{
    /**
     * 获取实例 ID
     */
    public function getId(): string;

    /**
     * 获取服务名称
     */
    public function getName(): string;

    /**
     * 获取主机地址
     */
    public function getHost(): string;

    /**
     * 获取端口
     */
    public function getPort(): int;

    /**
     * 是否健康
     */
    public function isHealthy(): bool;

    /**
     * 获取权重
     */
    public function getWeight(): int;

    /**
     * 获取元数据
     */
    public function getMetadata(): array;

    /**
     * 获取元数据项
     */
    public function getMeta(string $key, mixed $default = null): mixed;

    /**
     * 获取最后心跳时间
     */
    public function getLastHeartbeat(): int;

    /**
     * 转换为数组
     */
    public function toArray(): array;

    /**
     * 从数组创建
     */
    public static function fromArray(array $data): static;
}
