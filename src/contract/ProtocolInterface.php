<?php

declare(strict_types=1);

namespace qs9000\rpc\contract;

use qs9000\rpc\RpcException;

/**
 * RPC 协议编解码器接口
 */
interface ProtocolInterface
{
    /**
     * 编码请求
     */
    public function encodeRequest(
        string $id,
        string $method,
        array $params = [],
        ?string $version = null,
        int $timeout = 5000
    ): string;

    /**
     * 解码请求
     */
    public function decodeRequest(string $packet): array;

    /**
     * 编码响应
     */
    public function encodeResponse(string $id, mixed $result = null, ?RpcException $error = null): string;

    /**
     * 解码响应
     */
    public function decodeResponse(string $packet): array;

    /**
     * 编码心跳包
     */
    public function encodeHeartbeat(): string;

    /**
     * 解码心跳包
     */
    public function decodeHeartbeat(string $packet): array;
}
