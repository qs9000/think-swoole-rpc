<?php

declare(strict_types=1);

namespace qs9000\rpc;

use Closure;
use think\App;
use think\Pipeline;
use InvalidArgumentException;
use think\swoole\rpc\Protocol;

/**
 * RPC 中间件管理器
 * 
 * 参考 think-swoole 的中间件实现方式
 * 中间件配置只需要类名，不需要传递参数
 * 参数通过依赖注入或构造函数传递
 * 
 * @package qs9000\rpc
 */
class Middleware
{
    /**
     * 中间件执行队列
     * @var array
     */
    protected array $queue = [];

    /**
     * @var App|null
     */
    protected ?App $app = null;

    public function __construct(?App $app = null, array $middlewares = [])
    {
        $this->app = $app;

        foreach ($middlewares as $middleware) {
            $this->queue[] = $this->buildMiddleware($middleware);
        }
    }

    /**
     * 创建中间件实例
     *
     * @param App|null $app
     * @param array $middlewares
     * @return self
     */
    public static function make(?App $app = null, array $middlewares = []): self
    {
        return new self($app, $middlewares);
    }

    /**
     * 调度管道
     *
     * @return Pipeline
     */
    public function pipeline(): Pipeline
    {
        return (new Pipeline())
            ->through(array_map(function ($middleware) {
                return function (Protocol $protocol, $next) use ($middleware) {
                    [$call, $params] = $middleware;

                    if (is_array($call) && is_string($call[0])) {
                        // 从容器获取中间件实例（支持依赖注入）
                        try {
                            // 如果有参数，通过构造函数传递
                            if (!empty($params)) {
                                $instance = $this->app?->make($call[0], $params) ?? new $call[0](...$params);
                            } else {
                                $instance = $this->app?->make($call[0]) ?? new $call[0]();
                            }
                            $call = [$instance, $call[1]];
                        } catch (\Throwable $e) {
                            throw new InvalidArgumentException(
                                "Failed to instantiate middleware '{$call[0]}': " . $e->getMessage(),
                                0,
                                $e
                            );
                        }
                    }
                    
                    return $call($protocol, $next);
                };
            }, $this->queue));
    }

    /**
     * 解析中间件
     *
     * @param mixed $middleware
     * @return array
     */
    protected function buildMiddleware(mixed $middleware): array
    {
        if ($middleware instanceof Closure) {
            // 闭包格式
            return [$middleware, []];
        }

        if (is_array($middleware)) {
            // 数组格式：[类名, 参数]
            [$class, $params] = $middleware;
            
            if (!is_string($class)) {
                throw new InvalidArgumentException('The middleware class name is invalid');
            }
            
            return [[$class, 'handle'], $params];
        }

        if (!is_string($middleware)) {
            throw new InvalidArgumentException('The middleware is invalid');
        }

        // 类名字符串格式：'ClassName' → 调用 handle 方法，不传参数
        return [[$middleware, 'handle'], []];
    }

    /**
     * 添加中间件
     *
     * @param mixed $middleware
     * @return self
     */
    public function add(mixed $middleware): self
    {
        $this->queue[] = $this->buildMiddleware($middleware);
        return $this;
    }

    /**
     * 批量添加中间件
     *
     * @param array $middlewares
     * @return self
     */
    public function use(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->add($middleware);
        }
        return $this;
    }

    /**
     * 清空中间件
     *
     * @return self
     */
    public function flush(): self
    {
        $this->queue = [];
        return $this;
    }
}
