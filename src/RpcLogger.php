<?php

declare(strict_types=1);

namespace qs9000\rpc;

/**
 * RPC 日志工具类
 * 
 * 统一日志处理，优先使用 ThinkPHP Log facade，降级到 error_log
 * 
 * @package qs9000\rpc
 */
class RpcLogger
{
    /**
     * 记录调试日志
     *
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * 记录信息日志
     *
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * 记录警告日志
     *
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * 记录错误日志
     *
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * 记录关键错误日志
     *
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /**
     * 通用日志方法
     *
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    protected static function log(string $level, string $message, array $context = []): void
    {
        // 格式化上下文信息
        if (!empty($context)) {
            $message .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        // 尝试使用 ThinkPHP Log facade
        if (class_exists('\think\facade\Log')) {
            try {
                \think\facade\Log::$level($message);
                return;
            } catch (\Throwable $e) {
                // 如果 Log facade 调用失败，降级到 error_log
            }
        }

        // 降级到 error_log
        $prefix = sprintf('[RPC-%s] ', strtoupper($level));
        error_log($prefix . $message);
    }

    /**
     * 检查是否启用调试模式
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        if (function_exists('config')) {
            return (bool) config('rpc.debug', false);
        }
        return false;
    }
}
