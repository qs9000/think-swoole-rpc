<?php

declare(strict_types=1);

namespace qs9000\rpc;

use think\Service;
use qs9000\rpc\client\BindInterface;
use qs9000\rpc\contract\ServerInfoInterface;
use qs9000\rpc\server\ServerInfo;

class RpcClientService extends Service
{
    public function register()
    {
        $this->app->make(BindInterface::class)->bind();
    }
}
