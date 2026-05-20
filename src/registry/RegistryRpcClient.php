<?php

declare(strict_types=1);

namespace qs9000\rpc\registry;

use InvalidArgumentException;
use qs9000\rpc\registry\RegistryClientInterface;

class RegistryRpcClient implements RegistryClientInterface
{
    private RegistryClientInterface $registry;
    public function __construct()
    {
        $this->registry = app()->make(RegistryClientInterface::class);
    }
    /**
     *@inheritDoc
     */
    public function register(string $type, array $data): bool
    {
        if (empty($data)) {
            throw new InvalidArgumentException('注册数据不能为空。');
        }

        return $this->registry->register($type, $data);
    }

    /**
     *@inheritDoc
     */
    public function unregister(string $type, string $serviceName): bool
    {
        $this->validateServiceName($serviceName);

        return $this->registry->unregister($type, $serviceName);
    }

    /**
     *@inheritDoc
     */
    public function heartbeat(string $type, string $serviceName): bool
    {
        $this->validateServiceName($serviceName);

        return $this->registry->heartbeat($type, $serviceName);
    }

    /**
     *@inheritDoc
     */    public function health(string $type, string $serviceName): bool
    {
        $this->validateServiceName($serviceName);

        return $this->registry->health($type, $serviceName);
    }

    /**
     *@inheritDoc
     */
    public function discover(string $type, string $serviceName): array
    {
        $this->validateServiceName($serviceName);

        return $this->registry->discover($type, $serviceName);
    }

    /**
     *@inheritDoc
     */
    public function list(string $type, string $serviceName = ''): array
    {
        // list 方法允许空字符串以获取所有服务，因此不做非空校验，但可做基本格式校验如果非空
        if ($serviceName !== '') {
            $this->validateServiceName($serviceName);
        }

        return $this->registry->list($type, $serviceName);
    }

    /**
     *@inheritDoc
     */
    public function listHost(string $type, string $host = '*', string $port = '*'): array
    {
        // 对于 host 和 port，如果是通配符 '*' 则跳过校验，否则进行基本校验
        if ($host !== '*' && $host !== '') {
            $this->validateServiceName($host); // 复用服务名校验逻辑，或可单独为主机名校验
        }

        if ($port !== '*' && $port !== '') {
            // 端口通常是数字，但这里定义为字符串且支持通配符，仅做非空和基本字符校验
            if (!preg_match('/^[0-9*]+$/', $port)) {
                throw new InvalidArgumentException('端口只能包含数字或通配符 *。');
            }
        }

        return $this->registry->listHost($type, $host, $port);
    }

    /**
     * 验证服务名称
     *
     * @param string $serviceName
     * @throws InvalidArgumentException
     */
    private function validateServiceName(string $serviceName): void
    {
        if ($serviceName === '') {
            throw new InvalidArgumentException('服务名称不能为空。');
        }

        // 基本的安全校验：只允许字母、数字、横杠、下划线、点号
        if (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $serviceName)) {
            throw new InvalidArgumentException('服务名称包含无效字符。');
        }
    }
}
