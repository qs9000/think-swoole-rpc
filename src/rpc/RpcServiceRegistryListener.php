<?php

declare(strict_types=1);

namespace qs9000\rpc\rpc;

use qs9000\rpc\ServiceRegister;
use think\Event;
use think\swoole\Manager;

/**
 * RPC 服务注册事件监听器
 *
 * 自动注册到 think-swoole 的事件系统中
 *
 * 注册方式 (bootstrap/listener.php 或 app.php):
 * ```php
 * use qs9000\rpc\rpc\RpcServiceRegistryListener;
 *
 * $event->listen('swoole.manager.started', [RpcServiceRegistryListener::class, 'onManagerStarted']);
 * $event->listen('swoole.manager.stopping', [RpcServiceRegistryListener::class, 'onManagerStopping']);
 * ```
 */
class RpcServiceRegistryListener
{
    /** @var ServiceRegister|null */
    protected static ?ServiceRegister $register = null;

    /**
     * Manager 启动完成事件
     *
     * @param Manager $manager
     */
    public static function onManagerStarted(Manager $manager): void
    {
        $config = $manager->getConfig();

        $registryConfig = $config->get('rpc.registry', []);

        if (empty($registryConfig['enable'])) {
            return;
        }

        self::$register = ServiceRegister::fromConfig([
            'registry' => $registryConfig,
        ]);

        $serverConfig = $config->get('rpc.server', []);

        if (!empty($serverConfig) && self::$register->register($serverConfig)) {
            self::$register->startHeartbeat();
        }
    }

    /**
     * Manager 停止中事件
     *
     * @param Manager $manager
     */
    public static function onManagerStopping(Manager $manager): void
    {
        if (self::$register === null) {
            return;
        }

        $config = $manager->getConfig();
        $serverConfig = $config->get('rpc.server', []);

        self::$register->deregister($serverConfig);
    }
}
