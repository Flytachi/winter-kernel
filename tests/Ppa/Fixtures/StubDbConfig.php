<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Fixtures;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Cdo\Connection\CDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Minimal no-op DbConfigInterface stub used by Ppa tests.
 * Tests that need a specific driver name override {@see getDriver()}.
 * DeclarationItem only inspects the class via reflection — none of the
 * connection-related methods are called.
 */
abstract class StubDbConfig implements DbConfigInterface
{
    protected string $driver = 'pgsql';

    public function setUp(): void
    {
    }

    public function getDns(): string
    {
        return '';
    }

    public function getPersistentStatus(): bool
    {
        return false;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getUsername(): string
    {
        return '';
    }

    public function getPassword(): string
    {
        return '';
    }

    public function connect(int $timeout = 3): void
    {
    }

    public function disconnect(): void
    {
    }

    public function reconnect(): void
    {
    }

    public function connection(): CDO
    {
        throw new \RuntimeException('Stub: no real connection');
    }

    public function ping(): bool
    {
        return true;
    }

    public function pingDetail(): array
    {
        return ['status' => true, 'latency' => 0.0, 'error' => null];
    }

    public function getSchema(): ?string
    {
        return null;
    }

    public function getLogger(): LoggerInterface
    {
        return new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
    }
}
