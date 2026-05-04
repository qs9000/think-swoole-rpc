<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\App;
use think\swoole\rpc\Protocol;
use qs9000\rpc\rpc\DiscoveryGateway;

/**
 * RPC 接口自动绑定器
 * 
 * 解决 think-swoole rpc.php 文件在动态服务发现场景下的使用问题：
 * 
 * 传统方式（think-swoole 内置）：
 * - 读取 base_path() . 'rpc.php' 文件
 * - 为每个接口创建固定的 Gateway（包含固定配置）
 * - 通过 Proxy 类生成代理对象
 * 
 * 动态服务发现方式：
 * - 读取 rpc.php 文件中声明的接口列表
 * - 将所有接口绑定到同一个 DiscoveryGateway
 * - 每次调用时动态获取服务实例
 * 
 * @package qs9000\rpc
 */
class RpcInterfaceBinder
{
    /** @var App ThinkPHP 应用容器 */
    protected App $app;

    /** @var DiscoveryGateway 动态服务发现网关 */
    protected DiscoveryGateway $gateway;

    public function __construct(App $app, DiscoveryGateway $gateway)
    {
        $this->app = $app;
        $this->gateway = $gateway;
    }

    /**
     * 绑定所有 RPC 接口
     * 
     * 此方法应该在应用启动时调用（例如在 ServiceProvider 的 boot 方法中）
     */
    public function bindAll(): void
    {
        $rpcFile = $this->app->getBasePath() . 'rpc.php';

        if (!file_exists($rpcFile)) {
            // rpc.php 文件不存在，跳过绑定
            return;
        }

        $rpcServices = (array) include $rpcFile;

        foreach ($rpcServices as $clientName => $abstracts) {
            $this->bindClientInterfaces($clientName, $abstracts);
        }
    }

    /**
     * 绑定指定客户端的所有接口
     *
     * @param string $clientName 客户端名称
     * @param array $abstracts 接口类名列表
     */
    protected function bindClientInterfaces(string $clientName, array $abstracts): void
    {
        foreach ($abstracts as $interfaceClass) {
            $this->bindInterface($interfaceClass);
        }
    }

    /**
     * 绑定单个接口
     *
     * @param string $interfaceClass 接口完整类名
     */
    protected function bindInterface(string $interfaceClass): void
    {
        // 检查接口是否存在
        if (!interface_exists($interfaceClass)) {
            // 接口不存在，跳过（可能是可选依赖）
            return;
        }

        // 将接口绑定到动态代理类
        $this->app->bind($interfaceClass, function (App $app) use ($interfaceClass) {
            return $this->createDynamicProxy($interfaceClass);
        });
    }

    /**
     * 创建动态代理对象
     * 
     * 这个代理对象会：
     * 1. 拦截所有方法调用
     * 2. 构建 Protocol 对象
     * 3. 通过 DiscoveryGateway 调用远程服务
     *
     * @param string $interfaceClass 接口完整类名
     * @return object 动态代理对象
     */
    protected function createDynamicProxy(string $interfaceClass): object
    {
        $interfaceShortName = class_basename($interfaceClass);
        $gateway = $this->gateway;

        // 使用匿名类实现接口
        return new class($interfaceClass, $interfaceShortName, $gateway) implements \ArrayAccess {
            private string $interfaceClass;
            private string $interfaceShortName;
            private DiscoveryGateway $gateway;

            public function __construct(
                string $interfaceClass,
                string $interfaceShortName,
                DiscoveryGateway $gateway
            ) {
                $this->interfaceClass = $interfaceClass;
                $this->interfaceShortName = $interfaceShortName;
                $this->gateway = $gateway;
            }

            /**
             * 魔术方法：拦截所有方法调用
             */
            public function __call(string $method, array $arguments)
            {
                // 构建协议对象
                $protocol = Protocol::make(
                    $this->interfaceShortName,  // 使用接口短名称
                    $method,
                    $arguments[0] ?? []         // 第一个参数作为 params
                );

                // 通过 DiscoveryGateway 调用
                return $this->gateway->call($protocol);
            }

            // ArrayAccess 接口实现（避免报错）
            public function offsetExists($offset): bool { return false; }
            public function offsetGet($offset): mixed { return null; }
            public function offsetSet($offset, $value): void {}
            public function offsetUnset($offset): void {}
        };
    }

    /**
     * 手动绑定指定接口
     *
     * @param string $interfaceClass 接口完整类名
     */
    public function bind(string $interfaceClass): void
    {
        $this->bindInterface($interfaceClass);
    }

    /**
     * 批量绑定接口
     *
     * @param array $interfaceClasses 接口类名列表
     */
    public function bindMany(array $interfaceClasses): void
    {
        foreach ($interfaceClasses as $interfaceClass) {
            $this->bindInterface($interfaceClass);
        }
    }
}
