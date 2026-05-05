<?php

declare(strict_types=1);

namespace qs9000\rpc\contract;

use think\swoole\rpc\Protocol;

/**
 * RPC 中间件接口
 * 
 * 参考 think-swoole 的中间件设计
 * 
 * @package qs9000\rpc\contract
 */
interface MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param Protocol $protocol RPC协议对象
     * @param callable $next 下一个处理者
     * @return mixed
     */
    public function handle(Protocol $protocol, callable $next): mixed;
}
