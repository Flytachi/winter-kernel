<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Pool;

use Flytachi\Winter\Cdo\Connection\CDOException;
use Flytachi\Winter\K2\ConnectionPool\PoolEntry;
use Flytachi\Winter\K2\Ppa\Pool\BorrowedConnection;
use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Evict-on-connection-loss (the coroutine path): a connection that died in use must be
 * marked for eviction and dropped from the coroutine context, while an ordinary query
 * error must leave the borrowed connection completely alone.
 *
 * The context is seeded with the same {@see BorrowedConnection} the borrow path stores,
 * so the decision is exercised without a live database.
 */
final class ReportFailureTest extends TestCase
{
    private const string CONFIG = 'App\\Config\\MainDb';

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the coroutine path needs Swoole.');
        }
    }

    private static function ctxKey(): string
    {
        return 'ppa_cdo_' . base64_encode(self::CONFIG);
    }

    /** A failure shaped exactly as CDO rethrows it: PDOException wrapped in CDOException. */
    private static function failure(string $sqlState): CDOException
    {
        $pdo = new PDOException('boom');
        $pdo->errorInfo = [$sqlState, null, 'boom'];

        return new CDOException('boom', previous: $pdo);
    }

    private static function held(): BorrowedConnection
    {
        return new BorrowedConnection(new PoolEntry(new \stdClass(), 0.0, 0.0, null));
    }

    public function test_a_lost_connection_is_marked_dead_and_dropped_from_the_context(): void
    {
        $out = [];
        \Swoole\Coroutine\run(static function () use (&$out): void {
            $held = self::held();
            \Swoole\Coroutine::getContext()[self::ctxKey()] = $held;

            $evicted = PpaConnectionPool::reportFailure(self::CONFIG, self::failure('08006'));

            $out = [
                'evicted'   => $evicted,
                'dead'      => $held->dead,
                'stillHeld' => isset(\Swoole\Coroutine::getContext()[self::ctxKey()]),
            ];
        });

        self::assertTrue($out['evicted']);
        self::assertTrue($out['dead'], 'the defer must evict this connection instead of pooling it again');
        self::assertFalse($out['stillHeld'], 'the next query in this request borrows a fresh connection');
    }

    public function test_a_query_error_leaves_the_connection_pooled(): void
    {
        $out = [];
        \Swoole\Coroutine\run(static function () use (&$out): void {
            $held = self::held();
            \Swoole\Coroutine::getContext()[self::ctxKey()] = $held;

            // 23505 — unique violation: the server is healthy, the query was not.
            $evicted = PpaConnectionPool::reportFailure(self::CONFIG, self::failure('23505'));

            $out = [
                'evicted'   => $evicted,
                'dead'      => $held->dead,
                'stillHeld' => isset(\Swoole\Coroutine::getContext()[self::ctxKey()]),
            ];
        });

        self::assertFalse($out['evicted']);
        self::assertFalse($out['dead'], 'a healthy connection must not be churned');
        self::assertTrue($out['stillHeld'], 'it stays borrowed for the rest of the request');
    }

    public function test_it_is_a_no_op_when_this_coroutine_holds_no_connection(): void
    {
        $evicted = true;
        \Swoole\Coroutine\run(static function () use (&$evicted): void {
            $evicted = PpaConnectionPool::reportFailure(self::CONFIG, self::failure('08006'));
        });

        self::assertFalse($evicted, 'nothing borrowed here — nothing to evict');
    }
}
