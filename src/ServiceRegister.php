<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\Event;
use qs9000\rpc\RegistryClient;
use qs9000\rpc\RpcException;
use qs9000\rpc\server\ServerInfo;
use think\facade\Log;
use think\facade\Config;
use swoole\Timer;

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
    /**
     * 存储服务器数据的数组
     *
     * @var array
     */
    protected array $serversData = [];

    /**
     * 存储服务数据的数组
     *
     * @var array
     */
    protected array $servicesData = [];

    /**
     * RPC注册客户端实例
     *
     * @var RegistryClient|null
     */
    protected ?RegistryClient $rpcRegistryClient = null;

    /**
     * 服务器注册客户端实例
     *
     * @var RegistryClient|null
     */
    protected ?RegistryClient $serverRegistryClient = null;

    /**
     * 注册配置数组
     *
     * @var array
     */
    protected array $registryConfig = [];

    /**
     * RPC功能是否启用标志
     *
     * @var bool
     */
    protected bool $rpcEnable = false;

    /**
     * 服务器功能是否启用标志
     *
     * @var bool
     */
    protected bool $serverEnable = false;

    /**
     * 用于存储 RPC 心跳定时器 ID，以便在停止时清理
     *
     * @var int
     */
    protected int $rpcHeartbeatTimerId = 0;

    /**
     * 用于存储 服务器心跳定时器 ID，以便在停止时清理
     *
     * @var int
     */
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
        $registryClass = $this->registryConfig['registry_class'] ?? null;
        // 获取基础配置
        $swooleConfig = Config::get('swoole', []);

        // 安全获取 ServerInfo 和 IP
        try {
            $serverInfoInstance = app()->make(ServerInfo::class);
            $serverIp = $serverInfoInstance->getServerIp($this->registryConfig['exclude_private'] ?? false);
        } catch (\Throwable $e) {
            Log::error("[Registry] 获取服务器IP失败: " . $e->getMessage());
            // 如果无法获取IP，注册器无法正常工作，直接禁用
            $this->serverEnable = false;
            $this->rpcEnable = false;
            return;
        }
        $serverConfig = $this->registryConfig['server'] ?? [];
        if (($serverConfig['enable'] ?? false)) {
            foreach ($swooleConfig as $serverName => $serverInfo) {
                // 确保 $serverInfo 是数组
                if (!is_array($serverInfo)) {
                    continue;
                }
                if (in_array($serverName, ['http', 'websocket'], true) && $serverInfo['enable']) {
                    $serverInfo['host'] = $serverIp;
                    $serverInfo['name'] = $serverName;
                    $this->serversData[] = $serverInfo;
                }
                if ($serverName == 'rpc') {
                    $rpcServersData[] = $serverInfo['server'];
                    if ($rpcServersData['enable'] ?? false) {
                        $serverInfo['host'] = $serverIp;
                        $serverInfo['name'] = $serverName;
                        $this->serversData[] = $rpcServersData;
                    }
                }
            }

            if (!empty($this->serversData)) {
                try {
                    $this->serverRegistryClient = app()->make($registryClass ?? RegistryClient::class, ['server'], true);
                    $this->serverEnable = true;
                } catch (\Throwable $e) {
                    Log::error("[Registry] 创建 Server 注册客户端失败: " . $e->getMessage());
                }
            }
        }

        // 处理 RPC 服务注册配置
        $rpcConfig = $this->registryConfig['rpc'] ?? [];
        $swooleRpc = $swooleConfig['rpc']['server'] ?? [];
        if ($rpcConfig['enable'] && ($swooleRpc['enable'] ?? false)) {
            $host = $serverIp;
            $port = $swooleRpc['port'] ?? 0;
            $weight = $swooleRpc['weight'] ?? 100;
            $metadata = $swooleRpc['metadata'] ?? [];

            if ($port > 0 && !empty($swooleRpc['services'])) {
                foreach ($swooleRpc['services'] as $serviceName => $interface) {
                    $name = class_basename($interface);
                    $this->servicesData[] = [
                        'name' => $name,
                        'host' => $host,
                        'port' => $port,
                        'weight' => $weight,
                        'metadata' => $metadata
                    ];
                }

                if (!empty($this->servicesData)) {
                    try {
                        $this->rpcRegistryClient = app()->make($registryClass ?? RegistryClient::class, ['rpc'], true);
                        $this->rpcEnable = true;
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
        if (!$this->serverEnable && !$this->rpcEnable) {
            return;
        }

        $event->listen('swoole.workerStart', function (string $workerId) {
            try {
                if (str_ends_with($workerId, '#0')) {
                    $this->register();
                    $this->heartbeat();
                }
            } catch (\Throwable $e) {
                Log::error("[Registry] 初始化过程中发生致命错误: " . $e->getMessage(), $e->getTrace());
            }
        });

        $event->listen('swoole.beforeWorkerStop', function () {
            try {
                $this->stopHeartbeats();
                $this->unregister();
            } catch (\Throwable $e) {
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
                try {
                    Timer::clear($this->rpcHeartbeatTimerId);
                    $this->rpcHeartbeatTimerId = 0;
                } catch (\Throwable $e) {
                    Log::warning("[Registry] 清除RPC心跳定时器失败: " . $e->getMessage());
                }
            }
            if ($this->serverHeartbeatTimerId > 0) {
                try {
                    Timer::clear($this->serverHeartbeatTimerId);
                    $this->serverHeartbeatTimerId = 0;
                } catch (\Throwable $e) {
                    Log::warning("[Registry] 清除服务器心跳定时器失败: " . $e->getMessage());
                }
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
    private function register(): void
    {
        if (!empty($this->servicesData) && $this->rpcRegistryClient && $this->rpcEnable) {
            foreach ($this->servicesData as $service) {
                try {
                    $this->rpcRegistryClient->register($service);
                } catch (\Throwable $e) {
                    Log::error("[RPC]服务{$service['name']}注册失败：" . $e->getMessage(), $e->getTrace());
                }
            }
        }

        if (!empty($this->serversData) && $this->serverRegistryClient && $this->serverEnable) {
            foreach ($this->serversData as $serverData) {
                try {
                    $this->serverRegistryClient->register($serverData);
                } catch (\Throwable $e) {
                    Log::error("[SERVER]{$serverData['name']}注册失败：" . $e->getMessage(), $e->getTrace());
                }
            }
        }
    }


    /**
     * 启动服务心跳机制
     *
     * 使用 Swoole Timer 定时向注册中心发送心跳包，以维持服务存活状态。
     * 心跳间隔从配置中读取，最小限制为 5 秒，最大限制为 300 秒。
     *
     * @return void
     */
    private function heartbeat(): void
    {
        if (!class_exists(Timer::class)) {
            Log::warning("[Registry] Swoole Timer 不存在，无法启动心跳机制");
            return;
        }
        if (!empty($this->servicesData) && $this->rpcEnable && $this->rpcRegistryClient) {
            $rpcConfig = $this->registryConfig['rpc'] ?? [];
            $heartbeatInterval = min(300, max(5, (int)($rpcConfig['heartbeat_interval'] ?? 30)));
            $servicesData = $this->servicesData;
            $rpcRegistryClient = $this->rpcRegistryClient;
            $this->rpcHeartbeatTimerId = Timer::tick($heartbeatInterval * 1000, function () use ($servicesData, $rpcRegistryClient) {
                try {
                    foreach ($servicesData as $service) {
                        if (!isset($service['name'], $service['host'], $service['port'])) {
                            continue;
                        }
                        $serviceName = "{$service['name']}:{$service['host']}:{$service['port']}";
                        $rpcRegistryClient->heartbeat($serviceName);
                    }
                } catch (\Throwable $e) {
                    Log::error("RPC服务发送心跳失败: " . $e->getMessage(), $e->getTrace());
                }
            });
        }

        if (!empty($this->serversData) && $this->serverEnable && $this->serverRegistryClient) {
            $serverConfig = $this->registryConfig['server'] ?? [];
            $heartbeatInterval = min(300, max(5, (int)($serverConfig['heartbeat_interval'] ?? 30)));
            $serversData = $this->serversData;
            $serverRegistryClient = $this->serverRegistryClient;
            $this->serverHeartbeatTimerId = Timer::tick($heartbeatInterval * 1000, function () use ($serversData, $serverRegistryClient) {
                // 再次检查实例状态，防止在极端情况下对象部分销毁
                if (empty($serversData) || !$serverRegistryClient) {
                    return;
                }

                try {
                    foreach ($serversData as $server) {
                        if (!isset($server['name'], $server['host'], $server['port'])) {
                            continue;
                        }
                        $serverName = "{$server['name']}:{$server['host']}:{$server['port']}";
                        $serverRegistryClient->heartbeat($serverName);
                    }
                } catch (\Throwable $e) {
                    Log::error("服务器发送心跳失败: " . $e->getMessage(), $e->getTrace());
                }
            });
        }
    }

    /**
     * 执行服务注销逻辑
     *
     * 在 Worker 停止前，从注册中心移除当前服务实例信息。
     * 即使注销失败，也仅记录日志而不中断流程。
     *
     * @return void
     */
    private function unregister(): void
    {
        if (!empty($this->servicesData) && $this->rpcEnable && $this->rpcRegistryClient) {
            try {
                foreach ($this->servicesData as $service) {
                    if (!isset($service['name'], $service['host'], $service['port'])) {
                        continue;
                    }
                    $serviceName = "{$service['name']}:{$service['host']}:{$service['port']}";
                    $result = $this->rpcRegistryClient->unregister($serviceName);
                    if (!$result) {
                        Log::warning("[Registry] RPC服务注销失败: {$serviceName}");
                    }
                }
            } catch (\Throwable $e) {
                // 记录注销失败
                Log::error("[Registry] RPC服务注销失败: " . $e->getMessage(), $e->getTrace());
            }
        }
        if (!empty($this->serversData) && $this->serverEnable && $this->serverRegistryClient) {
            try {
                foreach ($this->serversData as $server) {
                    if (!isset($server['name'], $server['host'], $server['port'])) {
                        continue;
                    }
                    $serverName = "{$server['name']}:{$server['host']}:{$server['port']}";
                    $this->serverRegistryClient->unregister($serverName);
                }
            } catch (\Throwable $e) {
                Log::error("[Registry] 服务器注销失败: " . $e->getMessage(), $e->getTrace());
            }
        }
    }
}
