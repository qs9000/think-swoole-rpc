<?php

declare(strict_types=1);

namespace qs9000\rpc\contract;

/**
 * 服务器信息接口
 *
 * 定义了获取服务器基本信息和健康状态检查的标准方法。
 */
interface ServerInfoInterface
{

    /**
     * 获取服务器详细信息
     *
     * @return array 包含服务器相关信息的关联数组
     */
    public function serverInfo(): array;

    /**
     * 检查服务器健康状态
     *
     * @return bool 如果服务器健康则返回 true，否则返回 false
     */
    public function health(): bool;
}
