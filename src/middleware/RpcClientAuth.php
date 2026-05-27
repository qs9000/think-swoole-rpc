<?php

declare(strict_types=1);

namespace qs9000\rpc\middleware;

use qs9000\rpc\contract\MiddlewareInterface;
use qs9000\rpc\RpcException;
use think\App;
use think\swoole\rpc\Protocol;

class RpcClientAuth implements MiddlewareInterface
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 处理协议请求，注入服务器身份验证信息
     *
     * 该中间件负责获取服务器配置名称和密钥，生成基于时间戳的 HMAC-SHA256 签名，
     * 并将服务器名称、时间戳和签名注入到协议上下文中，随后继续执行后续处理逻辑。
     *
     * @param Protocol $protocol 协议对象，用于获取和设置上下文信息
     * @param \Closure $next 下一个处理中间件的闭包
     * @return mixed 返回后续处理逻辑的结果
     * @throws RpcException 当服务器名称未配置或服务器密钥未配置时抛出异常
     */
    public function handle(Protocol $protocol, \Closure $next): mixed
    {
        // 验证服务器名称配置有效性
        $serverName = $this->app->config->get('app.name');
        if ($serverName === null || $serverName === '') {
            throw new RpcException('服务器名称未配置，请在config/app.php中配置app.name');
        }

        // 获取当前时间戳用于签名生成
        $timestamp = time();
        $cache = $this->app->config->get('rpc.server.auth.cache', 'system');
        // 验证服务器密钥配置有效性
        $secret = $this->app->cache->store($cache)->get("server_{$serverName}");
        if ($secret === null || $secret === '') {
            throw new RpcException('服务器密钥未配置，请在系统管理中配置服务器密钥');
        }

        // 构建签名字符串并生成 HMAC-SHA256 签名
        $signData = "{$serverName}{$timestamp}";
        $sign = hash_hmac('sha256', $signData, $secret);

        // 将服务器身份信息注入协议上下文
        $context = $protocol->getContext();
        $context['server_name'] = $serverName;
        $context['timestamp'] = $timestamp;
        $context['sign'] = $sign;
        $protocol->setContext($context);

        return $next($protocol);
    }
}
