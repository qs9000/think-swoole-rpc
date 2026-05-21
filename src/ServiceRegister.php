<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\Event;
use qs9000\rpc\RegistryClient;
use qs9000\rpc\RpcException;
use qs9000\rpc\server\ServerInfo;
use think\facade\Log;
use think\facade\Config;
use Swoole\Timer;

/**
 * 服务注册器
 *
 * 负责在 Swoole 服务器启动时将服务信息注册到注册中心。
 * 通过监听 Swoole 的初始化事件，自动读取配置并执行注册逻辑。
 *
 * @package qs9000\registry
 */
class ServiceRegister
{
    protected array $serversData = [];
    protected array $servicesData = [];
    protected ?RegistryClient $rpcRegistryClient = null;
    protected ?RegistryClient $serverRegistryClient = null;
    protected array $registryConfig = [];
    protected bool $enable = false;

    // 用于存储 Timer ID，以便在停止时清理
    protected int $rpcHeartbeatTimerId = 0;
    protected int $serverHeartbeatTimerId = 0;

    /**
     * 构造函数
     *
     * 初始化服务注册器，读取配置并准备服务数据。
     * 如果配置未启用或客户端创建失败，则标记为禁用状态。
     *
     */
    public function __construct()
    {
        // 安全地获取配置，防止键不存在导致错误
        $this->registryConfig = Config::get('rpc.registry', []);

        // 获取基础配置
        $config = Config::get('swoole', []);
        $localServerName = Config::get('app.name', 'unknown');

        // 安全获取 ServerInfo 和 IP
        try {
            $serverInfoInstance = app()->make(ServerInfo::class);
            $serverIp = $serverInfoInstance->getServerIp($this->registryConfig['exclude_private'] ?? false);
        } catch (\Throwable $e) {
            Log::error("[Registry] 获取服务器IP失败: " . $e->getMessage());
            // 如果无法获取IP，注册器无法正常工作，直接禁用
            $this->enable = false;
            return;
        }

        // 处理 Server 注册配置
        // 使用 ?? 防止数组键不存在
        $serverConfig = $this->registryConfig['server'] ?? [];
        if (!empty($serverConfig['enable'])) {
            foreach ($config as $serverName => $serverInfo) {
                // 确保 $serverInfo 是数组
                if (!is_array($serverInfo)) {
                    continue;
                }
                if (in_array($serverName, ['http', 'websocket', 'rpc'], true)) {
                    $serverInfo['host'] = $serverIp;
                    $this->serversData[] = array_merge(['name' => $localServerName, 'type' => $serverName], $serverInfo);
                }
            }

            if (!empty($this->serversData)) {
                try {
                    $this->serverRegistryClient = app()->make(RegistryClient::class, ['server']);
                    $this->enable = true;
                } catch (\Throwable $e) {
                    Log::error("[Registry] 创建 Server 注册客户端失败: " . $e->getMessage());
                }
            }
        }

        // 处理 RPC 服务注册配置
        $rpcConfig = $this->registryConfig['rpc'] ?? [];
        if (!empty($rpcConfig['enable']) && isset($config['rpc'])) {
            $rpc = $config['rpc'];
            $host = $serverIp;
            $port = $rpc['port'] ?? 0;

            // 修正拼写错误: metadate -> metadata
            $weight = $rpc['weight'] ?? 100;
            $metadata = $rpc['metadata'] ?? [];

            if ($port > 0 && !empty($rpc['services'])) {
                foreach ($rpc['services'] as $serviceName => $interface) {
                    $this->servicesData[] = [
                        'name' => $serviceName,
                        'host' => $host,
                        'port' => $port,
                        'weight' => $weight,
                        'metadata' => $metadata
                    ];
                }

                if (!empty($this->servicesData)) {
                    try {
                        $this->rpcRegistryClient = app()->make(RegistryClient::class, ['rpc']);
                        $this->enable = true;
                    } catch (\Throwable $e) {
                        Log::error("[Registry] 创建 RPC 注册客户端失败: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * 订阅 Swoole 初始化事件，并根据配置注册 RPC 服务。
     *
     * @param Event $event 事件对象，用于监听 Swoole 生命周期事件。
     * @return void
     */
    public function subscribe(Event $event): void
    {
        // 如果服务未启用，不注册任何监听器，节省资源
        if (!$this->enable) {
            return;
        }

        $event->listen('swoole.init', function () {
            try {
                $this->registerService();
                $this->registerServer();
                $this->startRpcHeartbeat();
                $this->startServerHeartbeat();
            } catch (\Throwable $e) {
                // 记录致命错误，并重新抛出以便上层捕获或终止启动
                Log::error("[Registry] 初始化过程中发生致命错误: " . $e->getMessage(), $e->getTrace());
                throw new RpcException($e->getMessage(), $e->getCode(), $e);
            }
        });

        $event->listen('swoole.beforeWorkerStop', function () {
            try {
                // 先停止心跳定时器，防止在注销过程中发送心跳
                $this->stopHeartbeats();

                $this->unregisterRpcService();
                $this->unregisterServerService();
            } catch (\Throwable $e) {
                // 注销失败不应阻止 Worker 停止，但应记录日志
                Log::error("[Registry] 服务注销过程中发生错误: " . $e->getMessage(), $e->getTrace());
            }
        });
    }

    /**
     * 停止所有心跳定时器
     *
     * @return void
     */
    private function stopHeartbeats(): void
    {
        if (class_exists(Timer::class)) {
            if ($this->rpcHeartbeatTimerId > 0) {
                Timer::clear($this->rpcHeartbeatTimerId);
                $this->rpcHeartbeatTimerId = 0;
            }
            if ($this->serverHeartbeatTimerId > 0) {
                Timer::clear($this->serverHeartbeatTimerId);
                $this->serverHeartbeatTimerId = 0;
            }
        }
    }

    /**
     * 执行服务注册逻辑
     *
     * 将预处理好的服务数据发送至注册中心。
     * 如果服务数据为空、功能未启用或客户端不存在，则跳过执行。
     *
     * @return void
     * @throws RpcException 当注册过程发生异常时抛出
     */
    private function registerService(): void
    {
        if (empty($this->servicesData) || !$this->rpcRegistryClient) {
            return;
        }

        try {
            $this->rpcRegistryClient->register($this->servicesData);
        } catch (\Throwable $e) {
            throw new RpcException("RPC服务注册失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 执行服务器注册逻辑
     *
     * 将预处理好的服务器数据发送至注册中心。
     * 如果服务器数据为空、功能未启用或客户端不存在，则跳过执行。
     *
     * @return void
     * @throws RpcException 当注册过程发生异常时抛出
     */
    private function registerServer(): void
    {
        if (empty($this->serversData) || !$this->serverRegistryClient) {
            return;
        }

        try {
            $this->serverRegistryClient->register($this->serversData);
        } catch (\Throwable $e) {
            throw new RpcException("服务器注册失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 启动服务心跳机制
     *
     * 使用 Swoole Timer 定时向注册中心发送心跳包，以维持服务存活状态。
     * 心跳间隔从配置中读取，最大限制为 30 秒。
     *
     * @return void
     */
    private function startRpcHeartbeat(): void
    {
        if (!class_exists(Timer::class)) {
            return;
        }

        if (empty($this->servicesData) || !$this->enable || !$this->rpcRegistryClient) {
            return;
        }

        // 使用构造函数中缓存的配置
        $config = $this->registryConfig;
        $rpcConfig = $config['rpc'] ?? [];

        // 确保间隔是正整数，默认30s，最大30s
        $heartbeatInterval = max(1, min(30, (int)($rpcConfig['heartbeat_interval'] ?? 30)));

        // 修复：在类方法中的匿名函数可以直接访问 $this (PHP 5.4+)
        // 但为了明确意图和避免潜在的序列化问题，我们依赖 $this 上下文
        $this->rpcHeartbeatTimerId = Timer::tick($heartbeatInterval * 1000, function () {
            // 再次检查实例状态，防止在极端情况下对象部分销毁
            if (empty($this->servicesData) || !$this->rpcRegistryClient) {
                return;
            }

            try {
                foreach ($this->servicesData as $service) {
                    if (!isset($service['name'], $service['host'], $service['port'])) {
                        continue;
                    }
                    $serviceName = "{$service['name']}:{$service['host']}:{$service['port']}";
                    $this->rpcRegistryClient->heartbeat($serviceName);
                }
            } catch (\Throwable $e) {
                Log::error("RPC服务发送心跳失败: " . $e->getMessage(), $e->getTrace());
            }
        });
    }

    /**
     * 启动服务器心跳机制
     *
     * 使用 Swoole Timer 定时向注册中心发送服务器心跳包，以维持服务器存活状态。
     * 心跳间隔从配置中读取，最大限制为 30 秒。
     *
     * @return void
     */
    private function startServerHeartbeat(): void
    {
        if (!class_exists(Timer::class)) {
            return;
        }

        if (empty($this->serversData) || !$this->enable || !$this->serverRegistryClient) {
            return;
        }

        // 使用构造函数中缓存的配置
        $config = $this->registryConfig;
        $serverConfig = $config['server'] ?? [];

        // 确保间隔是正整数
        $heartbeatInterval = max(1, min(30, (int)($serverConfig['heartbeat_interval'] ?? 30)));

        $this->serverHeartbeatTimerId = Timer::tick($heartbeatInterval * 1000, function () {
            // 再次检查实例状态，防止在极端情况下对象部分销毁
            if (empty($this->serversData) || !$this->serverRegistryClient) {
                return;
            }

            try {
                foreach ($this->serversData as $service) {
                    if (!isset($service['name'], $service['host'])) {
                        continue;
                    }
                    $serviceName = "{$service['name']}";
                    $this->serverRegistryClient->heartbeat($serviceName);
                }
            } catch (\Throwable $e) {
                Log::error("服务器发送心跳失败: " . $e->getMessage(), $e->getTrace());
            }
        });
    }

    /**
     * 执行服务注销逻辑
     *
     * 在 Worker 停止前，从注册中心移除当前服务实例信息。
     * 即使注销失败，也仅记录日志而不中断流程。
     *
     * @return void
     */
    private function unregisterRpcService(): void
    {
        if (empty($this->servicesData) || !$this->enable || !$this->rpcRegistryClient) {
            return;
        }

        try {
            foreach ($this->servicesData as $service) {
                if (!isset($service['name'], $service['host'], $service['port'])) {
                    continue;
                }
                $serviceName = "{$service['name']}:{$service['host']}:{$service['port']}";
                $this->rpcRegistryClient->unregister($serviceName);
            }
        } catch (\Throwable $e) {
            // 记录注销失败
            Log::error("[Registry] RPC服务注销失败: " . $e->getMessage(), $e->getTrace());
        }
    }

    /**
     * 执行服务器注销逻辑
     *
     * 在 Worker 停止前，从注册中心移除当前服务器实例信息。
     * 即使注销失败，也仅记录日志而不中断流程。
     *
     * @return void
     */
    private function unregisterServerService(): void
    {
        if (empty($this->serversData) || !$this->enable || !$this->serverRegistryClient) {
            return;
        }

        try {
            foreach ($this->serversData as $service) {
                if (!isset($service['name'], $service['host'])) {
                    continue;
                }
                $serviceName = "{$service['name']}";
                $this->serverRegistryClient->unregister($serviceName);
            }
        } catch (\Throwable $e) {
            // 记录注销失败
            Log::error("[Registry] 服务器注销失败: " . $e->getMessage(), $e->getTrace());
        }
    }
}
