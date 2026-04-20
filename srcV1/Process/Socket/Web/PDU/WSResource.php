<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Socket\Web\PDU;

use JetBrains\PhpStorm\Deprecated;

/**
 * Wraps a client connection resource, holding its state and buffers.
 */
#[Deprecated]
class WSResource
{
    /** @var resource The raw socket connection resource. */
    private $connect;

    /** @var array|null Information from the initial handshake. */
    private ?array $info;

    /** @var array A general-purpose key-value store for this connection's state (e.g., user ID, subscriptions). */
    private array $store = [];

    /**
     * @var string The input buffer for this connection.
     * Data from fread() is appended here until a full frame can be parsed.
     */
    public string $readBuffer = '';

    public string $writeBuffer = '';


    /**
     * @param resource $connect
     * @param array|null $info
     */
    public function __construct($connect, ?array $info = null)
    {
        $this->connect = $connect;
        $this->info = $info;
    }

    public function getStore(): array
    {
        return $this->store;
    }

    public function setStore(array $store): void
    {
        $this->store = $store;
    }

    /**
     * @return resource
     */
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

    public function __toString(): string
    {
        return (string) $this->connect;
    }
}
