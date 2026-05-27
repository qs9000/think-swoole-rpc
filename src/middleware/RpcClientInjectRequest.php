<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\swoole\rpc\Protocol;
use think\Request;

class RpcClientInjectRequest implements MiddlewareInterface
{
    private App $app;

    /**
     * 定义需要从 Request 注入到 Context 的字段映射
     * key 为 Request 中的属性名,value为 Context 中的键名
     */
    private const INJECT_FIELDS = [
        'traceId'    => 'traceId',
        'user_id'    => 'user_id',
        'dept_id'    => 'dept_id',
        'tenant_id'  => 'tenant_id',
        'company_id' => 'company_id',
        'user_role'  => 'user_role',
    ];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 处理rpc协议请求，请求中的信息注入到 Context 中。
     *
     * @param Protocol $protocol 协议对象，用于获取和设置上下文信息
     * @param \Closure $next 下一个中间件或处理逻辑的回调函数
     * @return mixed 返回后续处理流程的结果
     */
    public function handle(Protocol $protocol, \Closure $next): mixed
    {
        $context = $protocol->getContext();

        try {
            $request = $this->app->request;
        } catch (\Throwable $e) {
            return $next($protocol);
        }

        if (!$request instanceof Request) {
            return $next($protocol);
        }

        foreach (self::INJECT_FIELDS as $requestKey => $contextKey) {
            $context[$contextKey] = $request->$requestKey ?? null;
        }

        $protocol->setContext($context);
        return $next($protocol);
    }
}
