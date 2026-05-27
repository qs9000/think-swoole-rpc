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
        if ($rpc = $app->getBasePath() . 'rpc.php') {
            $this->services = (array) include $rpc;
        }
        $this->app = $app;
    }

    public function bind(): void
    {
        try {

            if (empty($this->services)) {
                return;
            }

            // 2. 注册 RPC 代理绑定
            $parser = $this->app->make(JsonParser::class);
            $tries = $this->app->config->get('rpc.client.tries') ?? 2;
            $middleware = $this->app->config->get('rpc.client.middleware') ?? [];

            foreach ($this->services as $serviceName => $serviceInterface) {
                if (!is_string($serviceInterface) || empty($serviceName)) {
                    continue;
                }

                $this->bindSingleService($serviceName, $serviceInterface, $parser, $tries, $middleware);
            }
        } catch (Throwable $e) {
            Log::error("RPC客户端绑定失败: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * 绑定单个服务
     *
     * @param string $serviceName
     * @param string $serviceInterface
     * @param JsonParser $parser
     * @param int $tries
     * @param array $middleware
     * @return void
     */
    protected function bindSingleService(
        string $serviceName,
        string $serviceInterface,
        JsonParser $parser,
        int $tries,
        array $middleware
    ): void {
        // 创建连接器并连接
        $connector = $this->app->make(Connector::class, ['serviceName' => $serviceName]);
        // 创建网关
        $gateway = new Gateway($connector, $parser, $tries);

        // 绑定到容器
        $this->app->bind($serviceInterface, function (App $app) use ($gateway, $middleware, $serviceName, $serviceInterface) {
            $proxyClass = Proxy::getClassName($serviceName, $serviceInterface);
            return $app->invokeClass($proxyClass, [$gateway, $middleware]);
        });
    }
}
