<?php
declare(strict_types=1);
namespace qs9000\rpc;
class RpcException extends \Exception
{
    public function __construct($message = "", $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}