<?php

declare(strict_types=1);

namespace qs9000\rpc;

/**
 * RPC 异常
 */
class RpcException extends \Exception
{
    protected mixed $data;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, mixed $data = null)
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /**
     * 获取附加数据
     */
    public function getData(): mixed
    {
        return $this->data;
    }
}
