<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Pool;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Isolates which operation inside CDO::__construct crashes pdo_mysql on PHP 8.4.
 *
 * Each test reconstructs the CDO connect sequence step-by-step against the
 * MYSQL_TEST_DSN, asserting truthiness after the operation. Tests run in
 * separate PHP processes (`#[RunInSeparateProcess]`) so a segfault in one
 * step does not abort the rest of the diagnostic.
 *
 * After CI reports the first crashing step, fix lands in winter-cdo
 * (constructor reorder / driver_options) — then `MysqlCrudTest` /
 * `MariadbCrudTest` go back to the `integration` (blocking) group.
 */
#[Group('pool')]
final class CdoMysqlSegfaultDiagnosticTest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    /** @return array{0:string,1:string,2:string} */
    private static function dsnTriplet(): array
    {
        return [
            (string) getenv('MYSQL_TEST_DSN'),
            (string) (getenv('MYSQL_TEST_USER') ?: 'root'),
            (string) (getenv('MYSQL_TEST_PASS') ?: ''),
        ];
    }

    #[RunInSeparateProcess]
    public function test_a_raw_pdo_with_default_options(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        self::assertSame('mysql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    #[RunInSeparateProcess]
    public function test_b_raw_pdo_with_errmode_exception(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_c_setAttribute_timeout_after_connect(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_d_setAttribute_persistent_after_connect(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_e_setAttribute_emulate_prepares_after_connect(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_f_set_time_zone_via_pdo_exec(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->exec("SET time_zone = '+00:00'");
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function test_g_full_cdo_attribute_sequence(): void
    {
        [$dsn, $u, $p] = self::dsnTriplet();
        $pdo = new \PDO($dsn, $u, $p);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
        $pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec("SET time_zone = '+00:00'");
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }
}
