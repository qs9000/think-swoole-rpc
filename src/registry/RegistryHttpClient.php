<?php

declare(strict_types=1);

namespace qs9000\rpc\registry;

use qs9000\rpc\registry\RegistryClientInterface;

/**
 * 注册中心Http客户端类
 * 提供服务注册、注销、心跳检测等功能
 */
class RegistryHttpClient implements RegistryClientInterface
{
    protected array $config;
    protected string $url;

    /**
     * 构造函数，初始化配置并验证URL格式
     * @throws \InvalidArgumentException 当配置无效时抛出异常
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        if (empty($this->config)) {
            throw new \InvalidArgumentException('注册中心配置无效');
        }

        // 检查是否配置了基础URL或主机和端口
        if (!isset($this->config['base_url']) && !(isset($this->config['host']) && isset($this->config['port']))) {
            throw new \InvalidArgumentException('必须配置 base_url 或 host 和 port  参数');
        }

        $baseUrl = $this->config['base_url'] ?? "http://{$this->config['host']}:{$this->config['port']}";

        // 验证URL格式
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('注册中心URL无效: ' . $baseUrl);
        }

        // 安全性增强：防止 SSRF，只允许 http 和 https 协议
        $parsedUrl = parse_url($baseUrl);
        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('注册中心URL协议不安全，仅支持 HTTP 或 HTTPS');
        }
        $this->url = rtrim($baseUrl, '/');
    }


    /**
     *@inheritDoc
     */
    public function register(string $type,array $data): bool
    {
        return $this->executeRequest('post', '/registry/register', ['type'=>$type,'data'=>$data]);
    }

    /**
     *@inheritDoc
     */
    public function unregister(string $type,string $serviceName): bool
    {
        return $this->executeRequest('post', '/registry/unregister', ['type'=>$type,'serverName' => $serviceName]);
    }

    /**
     *@inheritDoc
     */
    public function heartbeat(string $type,string $serviceName): bool
    {
        // 对路径参数进行编码，防止注入
        $encodedServiceName = rawurlencode($serviceName);
        return $this->executeRequest('get', "/registry/heartbeat/{$type}/{$encodedServiceName}");
    }

    /**
     *@inheritDoc
     */
    public function health(string $type,string $serviceName): bool
    {
        // 对路径参数进行编码
        $encodedServiceName = rawurlencode($serviceName);
        return $this->executeRequest('get', "/registry/health/{$type}.{$encodedServiceName}");
    }

    /**
     *@inheritDoc
     */
    public function discover(string $type,string $serviceName): array
    {
        // 对路径参数进行编码
        $encodedServiceName = rawurlencode($serviceName);
        return $this->executeRequest('get', "/registry/discover/{$type}/{$encodedServiceName}");
    }

    /**
     *@inheritDoc
     */
    public function list(string $type,string $serviceName = ''): array
    {
        // 对路径参数进行编码
        $encodedServiceName = rawurlencode($serviceName);
        return $this->executeRequest('get', "/registry/list/{$type}/{$encodedServiceName}");
    }


    /**
     *@inheritDoc
     */
    public function listHost(string $type,string $host = '*', string $port = '*'): array
    {
        // 对路径参数进行编码
        $encodedHost = rawurlencode($host);
        $encodedPort = rawurlencode($port);
        return $this->executeRequest('get', "/registry/hosts/{$type}/{$encodedHost}/{$encodedPort}");
    }

    /**
     * 执行HTTP请求到注册中心
     * @param string $method HTTP方法 (GET, POST, PUT, DELETE)
     * @param string $path 请求路径
     * @param mixed|null $data 请求数据
     * @return mixed 响应数据， 解析后的响应数据
     * @throws \RuntimeException 当请求失败时抛出异常
     */
    protected function executeRequest(string $method, string $path, mixed $data = null): mixed
    {
        $url = $this->url . $path;

        // 处理 GET 请求的参数
        $method = strtolower($method);
        if ($method === 'get' && !empty($data)) {
            // 将关联数组转换为查询字符串
            $queryString = http_build_query($data);
            if ($queryString !== '') {
                $separator = strpos($url, '?') !== false ? '&' : '?';
                $url .= $separator . $queryString;
            }
        }

        $curl = curl_init();

        // 设置 cURL 选项
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->config['timeout'] ?? 5000,
            CURLOPT_FOLLOWLOCATION => false, // 禁止重定向，防止 SSRF
            CURLOPT_SSL_VERIFYPEER => true,  // 验证 SSL 证书
            CURLOPT_SSL_VERIFYHOST => 2,     // 验证主机名
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // 使用 HTTP 1.1 以保持连接潜力
            CURLOPT_USERAGENT => 'qs9000-think-swoole-rpc-client/1.0', // 增加 User-Agent
        ]);

        if ($method === 'post' || $method === 'put' || $method === 'delete') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));

            // 处理 Body 数据
            if ($data !== null) {
                // 如果数据已经是字符串（例如 unregister 传入的服务名），直接编码或按需处理
                // 原代码逻辑：所有非 null 数据都尝试 json_encode
                $jsonData = is_string($data) ? $data : json_encode($data);

                // 如果是数组或对象，执行 json_encode
                if (!is_string($data)) {
                    $jsonData = json_encode($data);
                    if ($jsonData === false) {
                        curl_close($curl);
                        throw new \RuntimeException('Failed to encode request data to JSON: ' . json_last_error_msg());
                    }
                } else {
                    // 如果传入的是字符串，假设调用者希望直接发送该字符串作为 Body
                    // 或者，如果期望的是 JSON 字符串包装，则需 json_encode($data) -> "\"value\""
                    // 原代码行为：json_encode($data)。如果 $data 是 "abc", json_encode 得到 "\"abc\""。
                    // 这里保持原代码行为的一致性，对字符串也进行 json_encode 以符合 JSON Content-Type
                    $jsonData = json_encode($data);
                    if ($jsonData === false) {
                        curl_close($curl);
                        throw new \RuntimeException('Failed to encode request data to JSON: ' . json_last_error_msg());
                    }
                }

                curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonData)
                ]);
            }
        } elseif ($method === 'get') {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($curl);

        // 检查 cURL 错误
        if ($response === false) {
            $errorNo = curl_errno($curl);
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('Registry request failed (cURL Error ' . $errorNo . '): ' . $error);
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // 检查 HTTP 状态码
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("请求注册中心[{$url}]失败，HTTP状态码: {$httpCode}");
        }

        // 解析 JSON 响应
        $decodedResponse = json_decode($response, true);

        // 检查 JSON 解析错误
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('解码请求数据失败: ' . json_last_error_msg() . '. Response: ' . substr($response, 0, 200));
        }

        // 检查响应是否为数组
        if (!is_array($decodedResponse)) {
            if ($decodedResponse === null && $response !== 'null') {
                throw new \RuntimeException('响应格式错误，预期 JSON Object/Array');
            }
        }

        if (is_array($decodedResponse) && array_key_exists('code', $decodedResponse)) {
            $code = $decodedResponse['code'];
            // 假设 1 为成功状态码。如果业务定义不同，请调整此判断。
            if ($code === 0) {
                $msg = $decodedResponse['msg'] ?? 'Unknown business error';
                throw new \RuntimeException("请求注册中心[{$url}]业务失败 [Code: {$code}]: " . $msg);
            }
        }
        // 返回数据部分，如果不存在则返回 null
        return $decodedResponse['data'] ?? null;
    }
}
