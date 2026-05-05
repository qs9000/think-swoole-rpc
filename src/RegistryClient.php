<?php

declare(strict_types=1);

namespace qs9000\rpc;

/**
 * 注册中心 HTTP 客户端
 * 
 * 负责与注册中心的所有通信，提供：
 * - 服务注册/注销
 * - 服务发现
 * - 心跳保活
 * - 健康检查
 * 
 * 隔离网络通信细节，解耦 SDK 与注册中心实现
 *
 * @package qs9000\rpc
 */
class RegistryClient
{
    /** @var string 注册中心主机地址 */
    protected string $host;

    /** @var int 注册中心端口 */
    protected int $port;

    /** @var int 请求超时时间（毫秒） */
    protected int $timeout;

    /** @var string|null 认证 Token */
    protected ?string $token = null;

    /** @var string 基础 URL */
    protected string $baseUrl;

    /** @var \CurlHandle|null cURL 句柄（复用） */
    protected ?\CurlHandle $curlHandle = null;

    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?int $timeout = null
    ) {
        // 从配置加载默认值
        $config = [];
        if (function_exists('config')) {
            $config = config('rpc.registry', []);
        }
        
        $this->host = $host ?? $config['host'] ?? '127.0.0.1';
        $this->port = $port ?? $config['port'] ?? 9500;
        $this->timeout = $timeout ?? $config['timeout'] ?? 5000;
        $this->baseUrl = "http://{$this->host}:{$this->port}";
    }

    /**
     * 析构函数：释放 cURL 资源
     */
    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
        }
    }

    /**
     * 注册服务到注册中心
     *
     * @param array $serviceData 服务数据
     * @return array 响应结果
     */
    public function register(array $serviceData): array
    {
        return $this->post('/registry/register', $serviceData);
    }

    /**
     * 从注册中心注销服务
     *
     * @param array $serviceData 服务数据
     * @return array 响应结果
     */
    public function deregister(array $serviceData): array
    {
        return $this->post('/registry/deregister', $serviceData);
    }

    /**
     * 发现服务 - 获取单个可用实例（旧版 API，兼容用）
     *
     * @param string $serviceName 服务名称
     * @return array 响应结果
     */
    public function discover(string $serviceName): array
    {
        return $this->get('/registry/discover/' . urlencode($serviceName));
    }

    /**
     * 获取所有已注册的服务列表
     *
     * @return array 响应结果
     */
    public function getServices(): array
    {
        return $this->get('/registry/services');
    }

    /**
     * 获取指定服务的详细信息
     *
     * @param string $serviceName 服务名称
     * @return array 响应结果
     */
    public function getService(string $serviceName): array
    {
        return $this->get('/registry/service/' . urlencode($serviceName));
    }

    /**
     * 获取服务的所有实例列表
     *
     * @param string $serviceName 服务名称
     * @return array 响应结果
     */
    public function getInstances(string $serviceName): array
    {
        return $this->get('/registry/instances/' . urlencode($serviceName));
    }

    /**
     * 发送心跳保持服务活跃
     *
     * @param array $instanceData 实例数据
     * @return array 响应结果
     */
    public function heartbeat(array $instanceData): array
    {
        return $this->post('/registry/heartbeat', $instanceData);
    }

    /**
     * 健康检查
     *
     * @return array 响应结果
     */
    public function health(): array
    {
        return $this->get('/health');
    }

    /**
     * 获取注册中心基础 URL
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * 设置认证 Token
     *
     * @param string $token Bearer Token
     * @return self
     */
    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    /**
     * 设置超时时间
     *
     * @param int $timeout 超时时间（毫秒）
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(100, $timeout);
        return $this;
    }

    /**
     * GET 请求
     *
     * @param string $path 请求路径
     * @return array 解析后的响应
     */
    protected function get(string $path): array
    {
        $url = $this->buildUrl($path);
        
        $headers = ['Content-Type: application/json'];
        if ($this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        $ch = $this->getCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeout,
            CURLOPT_CONNECTTIMEOUT_MS => min(1000, $this->timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPGET => true,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // cURL 错误
        if ($body === false || $errno !== 0) {
            RpcLogger::error(
                'RegistryClient network error',
                [
                    'method' => 'GET',
                    'url' => $url,
                    'errno' => $errno,
                    'error' => $error ?: 'Unknown error',
                    'timeout' => $this->timeout,
                ]
            );
            
            return [
                'success' => false,
                'code' => -1,
                'msg' => sprintf(
                    'Network error (errno: %d): %s | URL: %s | Timeout: %dms',
                    $errno,
                    $error ?: 'Unknown error',
                    $url,
                    $this->timeout
                ),
            ];
        }

        return $this->parseResponse($body, $httpCode);
    }

    /**
     * POST 请求
     *
     * @param string $path 请求路径
     * @param array $data 请求数据
     * @return array 解析后的响应
     */
    protected function post(string $path, array $data = []): array
    {
        $url = $this->buildUrl($path);
        
        $headers = ['Content-Type: application/json'];
        if ($this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $ch = $this->getCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeout,
            CURLOPT_CONNECTTIMEOUT_MS => min(1000, $this->timeout),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // cURL 错误
        if ($response === false || $errno !== 0) {
            RpcLogger::error(
                'RegistryClient network error',
                [
                    'method' => 'POST',
                    'url' => $url,
                    'errno' => $errno,
                    'error' => $error ?: 'Unknown error',
                    'timeout' => $this->timeout,
                    'request_data_size' => strlen($body ?? ''),
                ]
            );
            
            return [
                'success' => false,
                'code' => -1,
                'msg' => sprintf(
                    'Network error (errno: %d): %s | URL: %s | Timeout: %dms',
                    $errno,
                    $error ?: 'Unknown error',
                    $url,
                    $this->timeout
                ),
            ];
        }

        return $this->parseResponse($response, $httpCode);
    }

    /**
     * 获取或创建 cURL 句柄（复用）
     *
     * @return \CurlHandle
     */
    protected function getCurlHandle(): \CurlHandle
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }
        return $this->curlHandle;
    }

    /**
     * 构建完整 URL
     *
     * @param string $path 路径
     * @return string 完整 URL
     */
    protected function buildUrl(string $path): string
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        $path = ltrim($path, '/');
        return "{$baseUrl}/{$path}";
    }

    /**
     * 解析响应数据
     *
     * @param string $body 响应体
     * @param int $httpCode HTTP 状态码
     * @return array 解析后的响应
     */
    protected function parseResponse(string $body, int $httpCode): array
    {
        // 空响应
        if (empty($body)) {
            return [
                'success' => false,
                'code' => $httpCode,
                'msg' => 'Empty response from registry',
            ];
        }

        // JSON 解析
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'code' => $httpCode,
                'msg' => sprintf('Invalid JSON: %s', json_last_error_msg()),
                'raw' => substr($body, 0, 500), // 只保留前 500 字符
            ];
        }

        // 兼容标准 HTTP 响应格式
        if (isset($data['code']) && isset($data['msg'])) {
            $data['success'] = ($data['code'] >= 200 && $data['code'] < 300);
        } elseif (isset($data['success'])) {
            // 已有 success 字段，保持原样
        } else {
            // 根据 HTTP 状态码判断
            $data['success'] = ($httpCode >= 200 && $httpCode < 300);
            $data['code'] = $httpCode;
            $data['msg'] = $httpCode === 200 ? 'OK' : 'HTTP ' . $httpCode;
        }

        return $data;
    }

    /**
     * 获取配置信息
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'base_url' => $this->baseUrl,
            'has_token' => $this->token !== null,
        ];
    }
}
