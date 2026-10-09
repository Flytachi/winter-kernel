<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Command\Db;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `db migrate` tells "the index is already there" from a real failure by the driver's own
 * error number: on MySQL/MariaDB every CREATE INDEX error shares SQLSTATE 42000.
 */
final class DbDriverErrorCodeTest extends TestCase
{
    private static function code(\Throwable $e): ?int
    {
        return new ReflectionMethod(Db::class, 'driverErrorCode')->invoke(null, $e);
    }

    private static function pdo(string $state, int $code): PDOException
    {
        $e = new PDOException("SQLSTATE[$state]: $code");
        $e->errorInfo = [$state, $code, 'message'];
        return $e;
    }

    public function test_the_driver_code_is_read_from_a_pdo_exception(): void
    {
        self::assertSame(1061, self::code(self::pdo('42000', 1061)));
        self::assertSame(1064, self::code(self::pdo('42000', 1064)), 'same SQLSTATE, a different failure');
    }

    public function test_the_driver_code_is_found_behind_a_wrapper(): void
    {
        self::assertSame(1061, self::code(new \RuntimeException('wrapped', 0, self::pdo('42000', 1061))));
    }

    public function test_a_failure_without_a_driver_code_has_none(): void
    {
        self::assertNull(self::code(new \RuntimeException('not a database error')));
        self::assertNull(self::code(new PDOException('no errorInfo')));
    }
}
