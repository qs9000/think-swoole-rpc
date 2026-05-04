<?php

declare(strict_types=1);

namespace qs9000\rpc;

/**
 * RPC 服务注册器
 *
 * 在服务启动时自动注册到注册中心
 */
class ServiceRegister
{
    /** @var string 注册中心地址 */
    protected string $registryHost;

    /** @var int 注册中心端口 */
    protected int $registryPort;

    /** @var int 注册中心超时(毫秒) */
    protected int $timeout;

    /** @var int 心跳间隔(秒) */
    protected int $heartbeatInterval = 30;

    /** @var array|null 已注册的服务信息 */
    protected ?array $registeredService = null;

    /** @var bool 是否已注册 */
    protected bool $registered = false;

    public function __construct(
        string $registryHost = '127.0.0.1',
        int $registryPort = 9500,
        int $timeout = 5000
    ) {
        $this->registryHost = $registryHost;
        $this->registryPort = $registryPort;
        $this->timeout = $timeout;
    }

    /**
     * 从配置创建注册器
     */
    public static function fromConfig(array $config): self
    {
        $registry = $config['registry'] ?? [];

        $instance = new self(
            $registry['host'] ?? '127.0.0.1',
            (int) ($registry['port'] ?? 9500),
            (int) ($registry['timeout'] ?? 5000)
        );

        if (isset($registry['heartbeat_interval'])) {
            $instance->setHeartbeatInterval((int) $registry['heartbeat_interval']);
        }

        return $instance;
    }

    /**
     * 注册服务
     *
     * @param array $serviceConfig 服务配置 (从 swoole.php 的 rpc.server 读取)
     * @return bool
     */
    public function register(array $serviceConfig): bool
    {
        $serviceData = $this->buildServiceData($serviceConfig);

        $response = $this->httpPost('/registry/register', $serviceData);

        if ($response && ($response['code'] ?? 0) >= 200 && ($response['code'] ?? 0) < 300) {
            $this->registered = true;
            $this->registeredService = $response['data'] ?? $serviceData;
            return true;
        }

        return false;
    }

    /**
     * 注销服务
     *
     * @param array $serviceConfig 服务配置
     * @return bool
     */
    public function deregister(array $serviceConfig): bool
    {
        if (!$this->registered && $this->registeredService === null) {
            return true;
        }

        $serviceData = $this->buildServiceData($serviceConfig);

        $response = $this->httpPost('/registry/deregister', $serviceData);

        $this->registered = false;
        $this->registeredService = null;

        return $response && ($response['code'] ?? 0) >= 200 && ($response['code'] ?? 0) < 300;
    }

    /**
     * 发送心跳
     *
     * @return bool
     */
    public function heartbeat(): bool
    {
        if ($this->registeredService === null) {
            return false;
        }

        $data = [
            'id' => $this->registeredService['id'] ?? null,
            'name' => $this->registeredService['name'] ?? $this->registeredService['service_name'] ?? null,
            'host' => $this->registeredService['host'] ?? null,
            'port' => $this->registeredService['port'] ?? null,
        ];

        if (empty($data['id']) && empty($data['name'])) {
            return false;
        }

        $response = $this->httpPost('/registry/heartbeat', $data);

        return $response && ($response['code'] ?? 0) >= 200 && ($response['code'] ?? 0) < 300;
    }

    /**
     * 启动心跳定时器 (在 Swoole 环境下)
     *
     * @return void
     */
    public function startHeartbeat(): void
    {
        if (!class_exists('\Swoole\Timer')) {
            return;
        }

        \Swoole\Timer::tick($this->heartbeatInterval * 1000, function () {
            $this->heartbeat();
        });
    }

    /**
     * 设置心跳间隔
     */
    public function setHeartbeatInterval(int $seconds): self
    {
        $this->heartbeatInterval = $seconds;
        return $this;
    }

    /**
     * 是否已注册
     */
    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * 获取已注册的服务信息
     */
    public function getRegisteredService(): ?array
    {
        return $this->registeredService;
    }

    /**
     * 构建服务数据
     */
    protected function buildServiceData(array $config): array
    {
        return [
            'name' => $config['service_name'] ?? $config['name'] ?? 'rpc-service',
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? 9000),
            'weight' => (int) ($config['weight'] ?? 100),
            'metadata' => $config['metadata'] ?? [],
        ];
    }

    /**
     * HTTP POST 请求
     */
    protected function httpPost(string $path, array $data): ?array
    {
        $url = sprintf('http://%s:%d%s', $this->registryHost, $this->registryPort, $path);

        $body = json_encode($data, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeout,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            return null;
        }

        $result = json_decode($response, true);

        if (!is_array($result)) {
            return null;
        }

        // 添加 HTTP 状态码
        $result['code'] = $httpCode;

        return $result;
    }
}
