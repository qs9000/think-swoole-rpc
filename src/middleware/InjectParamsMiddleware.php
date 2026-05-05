<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\swoole\rpc\Protocol;

/**
 * 参数注入中间件
 * 
 * 用户可以在构造函数中自定义需要注入的参数
 * 或者直接在 handle 方法中操作 $protocol 对象
 * 
 * @package qs9000\rpc\middleware
 */
class InjectParamsMiddleware implements MiddlewareInterface
{
    protected App $app;
    protected array $params;

    /**
     * 构造函数
     * 
     * @param App $app ThinkPHP 应用实例
     * @param array $params 需要注入的参数字段（可选）
     */
    public function __construct(App $app, array $params = [])
    {
        $this->app = $app;
        // 允许通过构造函数传递参数，默认为空数组
        // 用户可以在创建中间件实例时传入自定义参数
        $this->params = $params;
    }

    public function handle(Protocol $protocol, callable $next): mixed
    {
        // 合并额外参数到 Protocol 的 params 中
        if (!empty($this->params)) {
            $mergedParams = array_merge($protocol->getParams(), $this->params);
            $protocol->setParams($mergedParams);
        }

        return $next($protocol);
    }
}
