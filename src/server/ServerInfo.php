<?php

declare(strict_types=1);

namespace qs9000\rpc\server;

use qs9000\rpc\contract\ServerInfoInterface;
use think\App;

class ServerInfo implements ServerInfoInterface
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }
    /**
     * @inheritDoc
     */
    public function serverInfo(): array
    {
        return $this->collectServerInfo();
    }

    /**
     * @inheritDoc
     */
    public function health(): bool
    {
        return true;
    }
    /**
     * 收集服务器信息
     *
     * @return array 包含服务器、PHP、运行时、磁盘和时间信息的关联数组
     */
    private function collectServerInfo(): array
    {
        // 获取网站根目录并计算磁盘空间使用情况
        $rootDir = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
        $diskTotal = @disk_total_space($rootDir);
        $diskFree  = @disk_free_space($rootDir);

        // 预计算磁盘使用量，避免重复计算
        $diskUsed = ($diskTotal !== false && $diskFree !== false) ? ($diskTotal - $diskFree) : null;

        // 计算磁盘使用百分比，增加对 $diskTotal 为 0 的防护
        $usagePercent = null;
        if ($diskTotal !== false && $diskFree !== false && $diskTotal > 0) {
            $usagePercent = round(($diskTotal - $diskFree) / $diskTotal * 100, 1);
        }
        $excludePrivate = $this->app->config->get('rpc.server.exclude_private', false);
        return [
            'server' => [
                'ip'       => $this->app->config->get('app.host_ip') ?? $this->getServerIp($excludePrivate) ?? 'unknown',
                'hostname' => $this->app->config->get('app.name', 'unknown'),
                'arch'     => php_uname(),
                'software' => $_SERVER['SERVER_SOFTWARE']  ?? 'unknown',
                'port'     => $_SERVER['SERVER_PORT']      ?? null,
            ],
            'php' => [
                'version'            => PHP_VERSION,
                'sapi'               => PHP_SAPI,
                'timezone'           => date_default_timezone_get(),
                'memory_limit'       => ini_get('memory_limit'),
                'max_execution_time' => (int)ini_get('max_execution_time')
            ],
            'runtime' => [
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
                'peak_memory'  => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
                'load_avg'     => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
                'uptime'       => $this->getUptime(),
            ],
            'disk' => [
                'total' => $diskTotal !== false ? $this->formatBytes($diskTotal) : 'N/A',
                'free'  => $diskFree  !== false ? $this->formatBytes($diskFree)  : 'N/A',
                'used'  => $diskUsed !== null ? $this->formatBytes($diskUsed) : 'N/A',
                'usage_percent' => $usagePercent,
            ],
            'time' => [
                'server_time' => date('Y-m-d H:i:s'),
                'timestamp'   => time(),
            ],
        ];
    }

    /**
     * 获取系统运行时间（仅 Linux）
     *
     * @return string|null 格式化的运行时间字符串，若非Linux系统或获取失败则返回null
     */
    private function getUptime(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false) {
            return null;
        }

        $parts = explode(' ', trim($uptime), 2);
        if (!isset($parts[0])) {
            return null;
        }

        $seconds = (float) $parts[0];

        // 防止负数或异常值
        if ($seconds < 0) {
            return null;
        }

        $days    = floor($seconds / 86400);
        $hours   = floor(($seconds % 86400) / 3600);
        $mins    = floor(($seconds % 3600) / 60);

        return "{$days}天 {$hours}小时 {$mins}分钟";
    }

    /**
     * 字节数格式化
     *
     * @param int|float $bytes 字节数
     * @return string 格式化后的字节字符串（如 "1.5 GB"）
     */
    private function formatBytes(int|float $bytes): string
    {
        // 确保输入非负
        if ($bytes < 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }


    /**
     * 获取服务器 IP 地址
     *
     * @param bool $excludePrivate 是否排除私有网络地址 (RFC 1918)。
     *                             - true: 仅返回公网 IP (排除 10.x, 172.16-31.x, 192.168.x 等)
     *                             - false: 仅返回内网 IP (排除公网 IP, 127.0.0.1, 0.0.0.0, 169.254.x 等)
     * @return string 匹配到的第一个 IP 地址，未找到则返回空字符串
     */
    public function getServerIp(bool $excludePrivate = false): string
    {
        $hostIp = $this->app->config->get('app.host_ip');
        if ($hostIp) {
            return $hostIp;
        }
        $ips = [];
        if (function_exists('net_get_interfaces')) {
            $interfaces = net_get_interfaces();
            foreach ($interfaces as $name => $iface) {
                if (empty($iface['unicast']) || !is_array($iface['unicast'])) {
                    continue;
                }
                foreach ($iface['unicast'] as $addr) {
                    if (!isset($addr['address'])) {
                        continue;
                    }
                    $ip = $addr['address'];
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        continue;
                    }
                    $ips[] = $ip;
                }
            }
        } elseif (function_exists('swoole_get_local_ip')) {
            $interfaces = swoole_get_local_ip();
            foreach ($interfaces as $name => $iface) {
                if (!filter_var($iface, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }
                $ips[] = $iface;
            }
        } else {
            return '';
        }

        foreach ($ips as $ip) {
            // 排除回环、全零、链路本地地址
            if ($ip === '127.0.0.1' || $ip === '0.0.0.0' || str_starts_with($ip, '169.254.')) {
                continue;
            }

            $isPublic = (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($excludePrivate && $isPublic) {
                return $ip;
            }
            if (!$excludePrivate && !$isPublic) {
                return $ip;
            }
        }
        return '';
    }
}
