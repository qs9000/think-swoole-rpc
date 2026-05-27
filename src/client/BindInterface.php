<?php

namespace qs9000\rpc\client;

use qs9000\rpc\client\Connector;
use think\App;
use think\facade\Log;
use think\swoole\rpc\client\Gateway;
use think\swoole\rpc\client\Proxy;
use think\swoole\rpc\JsonParser;
use Throwable;

class BindInterface
{
    protected array $services = [];
    protected App $app;


    public function __construct(App $app)
    {
        $rpcPath = $app->getBasePath() . 'rpc.php';
        if (is_file($rpcPath)) {
            $this->services = (array) include $rpcPath;
            Log::info("[BindInterface] 加载 rpc.php 成功, 共 " . count($this->services) . " 个服务: " . implode(', ', array_keys($this->services)));
        } else {
            Log::warning("[BindInterface] rpc.php 文件不存在: {$rpcPath}");
        }
        $this->app = $app;
    }

    public function bind(): void
    {
        if (empty($this->services)) {
            Log::warning("[BindInterface] services 为空，跳过绑定");
            return;
        }

        Log::info("[BindInterface] 开始绑定 " . count($this->services) . " 个 RPC 服务");

        $parser   = $this->app->make(JsonParser::class);
        $tries    = $this->app->config->get('rpc.client.tries') ?? 2;
        $middleware = $this->app->config->get('rpc.client.middleware') ?? [];

        foreach ($this->services as $serviceName => $serviceInterfaces) {
            if (empty($serviceName)) {
                Log::warning("[BindInterface] 跳过空服务名: " . var_export($serviceInterfaces, true));
                continue;
            }

            // 兼容两种格式：'serviceName' => 'Interface' 或 'serviceName' => ['Interface1', 'Interface2']
            $interfaces = is_array($serviceInterfaces) ? $serviceInterfaces : [$serviceInterfaces];

            // 同一服务名的多个接口共享 Connector 和 Gateway
            $connector = $this->app->make(Connector::class, ['serviceName' => $serviceName]);
            $gateway   = new Gateway($connector, $parser, $tries);

            foreach ($interfaces as $interface) {
                if (!is_string($interface)) {
                    Log::warning("[BindInterface] 跳过非法接口: {$serviceName} => " . var_export($interface, true));
                    continue;
                }

                try {
                    $this->bindInterface($serviceName, $interface, $gateway, $middleware);
                    Log::info("[BindInterface] 绑定成功: {$serviceName} => {$interface}");
                } catch (Throwable $e) {
                    Log::error("[BindInterface] 绑定失败 [{$serviceName} => {$interface}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                }
            }
        }

        Log::info("[BindInterface] 绑定完成");
    }

    /**
     * 绑定单个接口到容器
     *
     * @param string $serviceName      服务名称
     * @param string $serviceInterface 接口类名
     * @param Gateway $gateway         已创建的网关实例（同服务共享）
     * @param array $middleware        中间件配置
     * @return void
     */
    protected function bindInterface(
        string $serviceName,
        string $serviceInterface,
        Gateway $gateway,
        array $middleware
    ): void {
        // 绑定到容器
        $this->app->bind($serviceInterface, function (App $app) use ($gateway, $middleware, $serviceName, $serviceInterface) {
            $proxyClass = Proxy::getClassName($serviceName, $serviceInterface);
            return $app->invokeClass($proxyClass, [$gateway, $middleware]);
        });
    }
}
