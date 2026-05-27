<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\Service;
use qs9000\rpc\client\BindInterface;

class RpcClientService extends Service
{
    public function register()
    {
        $this->app->make(BindInterface::class)->bind();
    }
}
