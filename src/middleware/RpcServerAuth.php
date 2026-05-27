<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use think\App;
use think\swoole\rpc\Protocol;

/**
 * 服务端认证中间件
 */
class RpcServerAuth implements MiddlewareInterface
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 处理 RPC 请求的认证逻辑
     *
     * @param Protocol $protocol RPC 协议对象，包含请求上下文信息
     * @param \Closure $next 下一个中间件或处理程序的回调函数
     * @return mixed 返回下一个中间件的处理结果
     * @throws \Exception 当认证配置错误、签名验证失败或请求过期时抛出异常
     */
    public function handle(Protocol $protocol, \Closure $next): mixed
    {
        $config = $this->app->config->get('rpc.server.auth');

        // 如果未启用认证，直接放行
        if (!($config['enable'] ?? false)) {
            return $next($protocol);
        }

        // 如果配置了自定义认证类
        if (isset($config['auth_class'])) {
            $authClassName = $config['auth_class'];

            if (!is_string($authClassName) || !class_exists($authClassName)) {
                throw new \qs9000\rpc\RpcException('请配置正确的认证类');
            }

            $authClass = new $authClassName;

            // 确保自定义认证类有 handle 方法，防止运行时错误
            if (!method_exists($authClass, 'handle')) {
                throw new \qs9000\rpc\RpcException('请配置正确的认证类');
            }

            $result = $authClass->handle($protocol);

            // 如果 handle 返回了新的 protocol 对象则使用，否则使用原对象
            return $next($result ?? $protocol);
        }

        $content = $protocol->getContext();

        // 获取并验证服务器名称
        $serverName = $content['server_name'] ?? '';

        if (empty($serverName)) {
            throw new \qs9000\rpc\RpcException('未获取到请求来源：非法请求来源');
        }

        // 确保 serverName 是字符串，防止后续拼接出错
        $serverName = (string) $serverName;

        // 获取签名和时间戳
        $sign = isset($content['sign']) ? (string) $content['sign'] : '';
        $timestamp = isset($content['timestamp']) ? (int) $content['timestamp'] : 0;
        $cache = $this->app->config->get('rpc.server.auth.cache', 'system');
        // 获取缓存实例
        try {
            $cache = $this->app->cache->store($cache);
        } catch (\Exception $e) {
            throw new \qs9000\rpc\RpcException('缓存未配置system');
        }

        // 获取服务器配置
        $server = $cache->get("server_{$serverName}");

        // 严格检查 $server 是否为有效数组且包含 secret
        if (!is_array($server) || !isset($server['secret']) || empty($server['secret'])) {
            throw new \qs9000\rpc\RpcException('非法的请求来源');
        }

        // 验证时间戳有效性
        if (abs(time() - $timestamp) > 300) {
            throw new \qs9000\rpc\RpcException('请求已过期');
        }

        // 计算期望的签名
        $expectedSign = hash_hmac('sha256', "{$serverName}{$timestamp}", $server['secret']);

        // 安全比较签名
        if (!hash_equals($expectedSign, $sign)) {
            throw new \qs9000\rpc\RpcException('服务签名验证失败');
        }

        return $next($protocol);
    }
}
