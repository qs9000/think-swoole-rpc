<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\swoole\rpc\Protocol;

/**
 * 参数注入中间件
 * 
 * 将参数注入到app->request中
 */
class RpcServerInjectRequest implements MiddlewareInterface
{
    private App $app;

    /**
     * 定义需要从 Context 注入到 Request 的字段映射
     * key 为 Context 中的键名，value 为 Request 中的属性名
     */
    private const INJECT_FIELDS = [
        'user_id'   => 'user_id',
        'dept_id'   => 'dept_id',
        'tenant_id' => 'tenant_id',
        'company_id' => 'company_id',
        'user_role' => 'user_role',
    ];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Protocol $protocol, \Closure $next): mixed
    {
        // 获取上下文，确保为数组类型
        $context = $protocol->getContext();
        if (!is_array($context)) {
            $context = [];
        }

        // 生成或获取追踪 ID
        $traceId = $context['traceId'] ?? null;
        if (empty($traceId)) {
            $traceId = uniqid('fac_', true);
        }
        // 更新上下文中的 traceId
        $context['traceId'] = $traceId;

        // 获取请求对象
        $request = $this->app->request;

        // 注入 traceId
        $request->traceId = $traceId;

        // 批量注入其他字段
        foreach (self::INJECT_FIELDS as $contextKey => $requestProp) {
            // 使用 null coalescing 确保不存在时为 null
            $value = $context[$contextKey] ?? null;
            $request->$requestProp = $value;
        }

        // 保存更新后的上下文
        $protocol->setContext($context);

        return $next($protocol);
    }
}
