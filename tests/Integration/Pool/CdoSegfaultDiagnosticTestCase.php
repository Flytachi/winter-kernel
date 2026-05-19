<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Pool;

use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Step-by-step reproducer for the CDO/pdo_mysql crash observed under PHP 8.4.
 *
 * Each step is a separate test method tagged with `#[RunInSeparateProcess]`,
 * so PHPUnit spawns a fresh PHP child per test. A segfault inside one child
 * cannot kill sibling tests — every step gets its own pass/fail signal.
 *
 * Steps a–g exercise raw PDO operations (driver-agnostic primitives that
 * CDO::__construct chains together). Step h exercises the full framework
 * path: PpaConnectionPool::db(<DbConfig>) → new CDO(...). Comparing a–g vs h
 * tells us whether the bug is in the raw operations or in the way the
 * framework chains/wraps them.
 *
 * Tagged with `cdo-diagnostic` group (excluded by default in phpunit.xml)
 * — opt in via `vendor/bin/phpunit --group cdo-diagnostic`.
 */
#[Group('cdo-diagnostic')]
abstract class CdoSegfaultDiagnosticTestCase extends IntegrationTestCase
{
    /** @return array{0:string,1:string,2:string} DSN, user, pass */
    abstract protected static function dsnTriplet(): array;

    /**
     * The DbConfig class used by the framework-path test (step h).
     *
     * @return class-string<\Flytachi\Winter\Cdo\Config\Common\DbConfigInterface>
     */
    abstract protected static function dbConfigClass(): string;

    #[RunInSeparateProcess]
    public function test_a_raw_pdo_with_default_options(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        self::assertSame('mysql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    #[RunInSeparateProcess]
    public function test_b_raw_pdo_with_errmode_exception(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_c_setAttribute_timeout_after_connect(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_d_setAttribute_persistent_after_connect(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_e_setAttribute_emulate_prepares_after_connect(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_f_set_time_zone_via_pdo_exec(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->exec("SET time_zone = '+00:00'");
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_g_full_cdo_attribute_sequence_on_raw_pdo(): void
    {
        [$dsn, $u, $p] = static::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
        $pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec("SET time_zone = '+00:00'");
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_h_via_framework_pool(): void
    {
        // If a–g all pass but h crashes — the bug is in the framework wrapper
        // (CDO::__construct + PpaConnectionPool::getConfigDb), not in PDO ops.
        $cdo = PpaConnectionPool::db(static::dbConfigClass());
        self::assertSame('mysql', $cdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, (int) $cdo->query('SELECT 1')->fetchColumn());
    }
}
