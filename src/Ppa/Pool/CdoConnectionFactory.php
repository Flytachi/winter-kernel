<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Pool;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\K2\ConnectionPool\ConnectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Adapts a CDO {@see DbConfigInterface} to the driver-agnostic
 * {@see ConnectionFactory} the {@see \Flytachi\Winter\K2\ConnectionPool\ConnectionPool}
 * drives.
 *
 * The pooled resource is the **config instance**, not the raw CDO — winter-cdo's
 * config owns the connection (`connection()`/`disconnect()`/`ping()`), so pooling the
 * config lets `close()` deterministically drop the socket and `validate()` reuse the
 * driver's own `SELECT 1` probe. Each {@see create()} builds a fresh config so every
 * pool slot gets an independent socket.
 */
final readonly class CdoConnectionFactory implements ConnectionFactory
{
    /**
     * @param class-string<DbConfigInterface> $configClass Config to instantiate per slot.
     * @param LoggerInterface $logger PPA channel logger, injected into each config.
     */
    public function __construct(
        private string $configClass,
        private LoggerInterface $logger,
    ) {
    }

    /** Opens one independent connection (own socket) via a fresh config instance. */
    public function create(): object
    {
        /** @var DbConfigInterface $config */
        $config = new ($this->configClass)();
        $config->setUp();
        $config->setLogger($this->logger);
        $config->connect();
        $this->logger->debug("slot opened: {$this->configClass} dsn={$config->getDns()}");
        return $config;
    }

    /** Liveness probe — `false` when the connection is dead. */
    public function validate(object $connection): bool
    {
        /** @var DbConfigInterface $connection */
        return self::probe($connection);
    }

    /**
     * Round-trips `SELECT 1` and reports whether the connection answered.
     *
     * It deliberately does **not** use `DbConfigInterface::ping()`: that method
     * catches `CDOException` only, while `PDO::query()` raises a `PDOException`
     * (unrelated to it), and its `return` inside `finally` swallows the exception —
     * so it answers `true` for a connection that is already dead. Verified against
     * live PostgreSQL and MariaDB: a killed connection still pinged `true`. Relying
     * on it would silently disable the idle-gate and keepalive, which exist
     * precisely to retire dead connections.
     *
     * Catching `Throwable` is the point: any failure to complete the round trip
     * means the connection cannot be handed out.
     */
    public static function probe(DbConfigInterface $config): bool
    {
        try {
            return $config->connection()->query('SELECT 1') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Drops the CDO reference so its socket is closed. */
    public function close(object $connection): void
    {
        /** @var DbConfigInterface $connection */
        $connection->disconnect();
    }
}
