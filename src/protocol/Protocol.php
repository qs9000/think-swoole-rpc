<?php

declare(strict_types=1);

namespace qs9000\rpc\protocol;

/**
 * RPC 协议常量定义
 *
 * 协议格式:
 * [4 bytes length][1 byte type][N bytes body]
 *
 * - Length: uint32 (big-endian), body 长度
 * - Type: 0x01=请求, 0x02=响应, 0x03=心跳
 * - Body: MessagePack 编码的数据
 */
class Protocol
{
    // 包类型
    public const TYPE_REQUEST = 0x01;
    public const TYPE_RESPONSE = 0x02;
    public const TYPE_HEARTBEAT = 0x03;

    // 包头长度 (4 + 1)
    public const HEADER_LENGTH = 5;

    // 最大包大小 (10MB)
    public const MAX_PACKET_SIZE = 10 * 1024 * 1024;

    // 请求结构字段
    public const FIELD_ID = 'id';
    public const FIELD_METHOD = 'method';
    public const FIELD_PARAMS = 'params';
    public const FIELD_TIMEOUT = 'timeout';
    public const FIELD_VERSION = 'version';

    // 响应结构字段
    public const FIELD_RESULT = 'result';
    public const FIELD_ERROR = 'error';
    public const FIELD_CODE = 'code';
    public const FIELD_MESSAGE = 'message';

    /**
     * 生成请求 ID
     */
    public static function generateId(): string
    {
        return sprintf(
            '%08x%04x%04x%04x%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffffffffffff)
        );
    }
}
