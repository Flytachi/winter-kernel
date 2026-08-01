<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Pool;

use Flytachi\Winter\Cdo\Connection\CDOException;
use Flytachi\Winter\K2\Ppa\Pool\ConnectionLoss;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The classifier that decides whether a failure justifies throwing the connection
 * away. Getting this wrong in either direction is costly: too eager and a healthy
 * pool churns on every constraint violation; too lax and a dead connection is handed
 * out again.
 */
final class ConnectionLossTest extends TestCase
{
    /** Builds a PDOException carrying a driver verdict, as PDO raises it. */
    private static function pdo(string $sqlState, ?int $driverCode = null, string $message = 'failure'): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = [$sqlState, $driverCode, $message];
        return $e;
    }

    /** CDO always wraps the original PDOException as `previous`. */
    private static function wrapped(PDOException $inner): CDOException
    {
        return new CDOException($inner->getMessage(), previous: $inner);
    }

    public function test_sqlstate_class_08_is_a_lost_connection(): void
    {
        foreach (['08000', '08001', '08003', '08004', '08006', '08007', '08S01'] as $state) {
            self::assertTrue(
                ConnectionLoss::isLost(self::pdo($state)),
                "SQLSTATE {$state} is a connection exception",
            );
        }
    }

    public function test_postgres_server_shutdown_states_are_a_lost_connection(): void
    {
        foreach (['57P01', '57P02', '57P03'] as $state) {
            self::assertTrue(ConnectionLoss::isLost(self::pdo($state)), "SQLSTATE {$state} terminates the connection");
        }
    }

    public function test_mysql_gone_away_driver_codes_are_a_lost_connection(): void
    {
        // MySQL reports these as HY000 plus a driver code.
        self::assertTrue(ConnectionLoss::isLost(self::pdo('HY000', 2006, 'MySQL server has gone away')));
        self::assertTrue(ConnectionLoss::isLost(self::pdo('HY000', 2013, 'Lost connection during query')));
    }

    public function test_query_errors_leave_the_connection_alone(): void
    {
        // A healthy server rejecting a bad query — evicting here would churn the pool.
        self::assertFalse(ConnectionLoss::isLost(self::pdo('23505', 7, 'duplicate key')), 'constraint violation');
        self::assertFalse(ConnectionLoss::isLost(self::pdo('42601', 7, 'syntax error')), 'syntax error');
        self::assertFalse(ConnectionLoss::isLost(self::pdo('23503', 7, 'foreign key')), 'foreign key violation');
        self::assertFalse(ConnectionLoss::isLost(self::pdo('HY000', 1213, 'deadlock')), 'deadlock — connection lives');
    }

    public function test_it_unwraps_the_cdo_exception_chain(): void
    {
        self::assertTrue(
            ConnectionLoss::isLost(self::wrapped(self::pdo('08006'))),
            'CDO wraps the PDOException, so the cause chain must be walked',
        );
        self::assertFalse(ConnectionLoss::isLost(self::wrapped(self::pdo('23505'))));
    }

    public function test_non_database_failures_are_not_a_lost_connection(): void
    {
        self::assertFalse(ConnectionLoss::isLost(new RuntimeException('something else')));
    }

    public function test_it_falls_back_to_the_code_when_error_info_is_absent(): void
    {
        // PDO raises connect-time failures with no errorInfo and SQLSTATE in the code.
        $error = new PDOException('could not connect');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($error, '08006');

        self::assertTrue(ConnectionLoss::isLost($error));
    }
}
