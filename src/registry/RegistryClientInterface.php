<?php

declare(strict_types=1);

namespace qs9000\rpc\registry;

/**
 * 服务注册中心客户端接口
 *
 * 定义了与服务注册中心交互的基本操作，包括服务的注册、注销、心跳维持、健康检查以及服务发现等功能。
 */
interface RegistryClientInterface
{
    /**
     * 注册服务实例
     *
     * @param string $type 服务类型或命名空间标识
     * @param array $data 服务注册信息，通常包含服务名称、主机地址、端口、权重等元数据
     * @return bool 注册成功返回 true，失败返回 false
     */
    public function register(string $type, array $data): bool;

    /**
     * 注销服务实例
     *
     * @param string $type 服务类型或命名空间标识
     * @param string $serverName 需要注销的服务名称或实例标识
     * @return bool 注销成功返回 true，失败返回 false
     */
    public function unregister(string $type, string $serverName): bool;

    /**
     * 发送心跳以维持服务存活状态
     *
     * @param string $type 服务类型或命名空间标识
     * @param string $serverName 服务名称或实例标识
     * @return bool 心跳更新成功返回 true，失败返回 false
     */
    public function heartbeat(string $type, string $serverName): bool;

    /**
     * 检查服务实例的健康状态
     *
     * @param string $type 服务类型或命名空间标识
     * @param string $serverName 服务名称或实例标识
     * @return bool 服务健康返回 true，不健康或不存在返回 false
     */
    public function health(string $type, string $serverName): bool;

    /**
     * 发现指定服务的可用实例列表
     *
     * @param string $type 服务类型或命名空间标识
     * @param string $serverName 目标服务名称
     * @return array 返回包含服务实例信息的数组，若未找到则返回空数组
     */
    public function discover(string $type, string $serverName): array;

    /**
     * 列出所有已注册的服务或指定服务的实例
     *
     * @param string $type 服务类型或命名空间标识
     * @param string $serverName 可选，指定服务名称。若为空字符串，则列出所有服务
     * @return array 返回服务列表数组
     */
    public function list(string $type, string $serverName = ''): array;

    /**
     * 根据主机和端口过滤列出服务实例
     *
     * @param string $type 服务类型或命名空间标识
     * @param string $host 主机地址，支持通配符 '*' 表示匹配所有主机
     * @param string $port 端口号，支持通配符 '*' 表示匹配所有端口
     * @return array 返回符合条件的服务实例列表数组
     */
    public function listHost(string $type, string $host = '*', string $port = '*'): array;
}
