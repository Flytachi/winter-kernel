<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Socket\Web\PDU;

class WSResource
{
    /** @var resource */
    private $connect;
    private ?array $info;
    private array $store = [];

    public string $readBuffer  = '';
    public string $writeBuffer = '';

    /** @param resource $connect */
    public function __construct($connect, ?array $info = null)
    {
        $this->connect = $connect;
        $this->info    = $info;
    }

    public function getConnect()
    {
        return $this->connect;
    }

    public function getInfo(string $key): mixed
    {
        return $this->info[$key] ?? null;
    }

    public function info(): array
    {
        return $this->info ?? [];
    }

    public function getStore(): array
    {
        return $this->store;
    }

    public function setStore(array $store): void
    {
        $this->store = $store;
    }

    public function __toString(): string
    {
        return (string) $this->connect;
    }
}
