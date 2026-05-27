<?php

declare(strict_types=1);

namespace qs9000\rpc\registry;

use think\App;

/**
 * 服务注册中心客户端接口
 *
 * 定义了与服务注册中心交互的基本操作，包括服务的注册、注销、心跳维持、健康检查以及服务发现等功能。
 */
interface RegistryClientInterface
{

    /**
     * 构造函数
     * @param App    $app  应用实例
     * @param string $type 服务类型，默认为 'rpc'
     */
    public function __construct(App $app, string $type = 'rpc');
    /**
     * 注册服务实例
     *
     * @param array $data 服务注册信息，通常包含服务名称、主机地址、端口、权重等元数据
     * @return bool 注册成功返回 true，失败返回 false
     */
    public function register(array $data): bool;

    /**
     * 注销服务实例
     *
     * @param string $key 服务名称或实例标识 name:host:port
     * @return bool 注销成功返回 true，失败返回 false
     */
    public function unregister(string $key): bool;

    /**
     * 发送心跳以维持服务存活状态
     *
     * @param string $key 服务实例标识 name:host:port
     * @return bool 心跳更新成功返回 true，失败返回 false
     */
    public function heartbeat(string $key): bool;

    /**
     * 检查服务实例的健康状态
     *
     * @param string $key 服务实例标识 name:host:port
     * @return bool 服务健康返回 true，不健康或不存在返回 false
     */
    public function health(string $key): bool;

    /**
     * 发现指定服务的可用实例列表
     *
     * @param string $name 目标服务名称
     * @return array 返回包含服务实例信息的数组，若未找到则返回空数组
     */
    public function discover(string $name): array;
}
