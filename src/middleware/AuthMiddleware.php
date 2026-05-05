<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\swoole\rpc\Protocol;

/**
 * 认证中间件
 * 
 * 用户可以在构造函数中自定义 Token 和字段名
 * 或者直接在 handle 方法中操作 $protocol 对象
 * 
 * @package qs9000\rpc\middleware
 */
class AuthMiddleware implements MiddlewareInterface
{
    protected App $app;
    protected string $token;
    protected string $field;

    /**
     * 构造函数
     * 
     * @param App $app ThinkPHP 应用实例
     * @param string $token 认证Token（可选，默认从环境变量读取）
     * @param string $field 字段名（可选，默认为 auth_token）
     */
    public function __construct(App $app, string $token = '', string $field = 'auth_token')
    {
        $this->app = $app;
        // 允许通过构造函数传递参数
        // 如果未传递，则从环境变量读取
        $this->token = $token ?: env('RPC_AUTH_TOKEN', '');
        $this->field = $field;
    }

    public function handle(Protocol $protocol, callable $next): mixed
    {
        // 在 Protocol 的 params 中添加认证字段
        if (!empty($this->token)) {
            $params = $protocol->getParams();
            $params[$this->field] = $this->token;
            $protocol->setParams($params);
        }
        
        return $next($protocol);
    }
}
