<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Concurrent\Executor;

use ArrayObject;
use Flytachi\Winter\Kernel\Concurrent\BoundedExecutorService;
use Flytachi\Winter\Kernel\Concurrent\Executor\FixedExecutorService;
use Flytachi\Winter\Kernel\Concurrent\Executors;
use Flytachi\Winter\Kernel\Concurrent\RejectedExecutionException;
use Flytachi\Winter\Kernel\Concurrent\RejectPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the fixed pool in a non-Swoole runtime: it delegates to the deferred
 * backend (sequential), the bound is a no-op, but the configuration, metrics,
 * factory and lifecycle all behave. The real concurrency cap is exercised
 * separately under the `swoole` group.
 */
final class FixedExecutorServiceTest extends TestCase
{
    public function test_factory_returns_a_bounded_executor(): void
    {
        $ex = Executors::newFixedExecutor(5);
        self::assertInstanceOf(BoundedExecutorService::class, $ex);
        self::assertInstanceOf(FixedExecutorService::class, $ex);
        self::assertSame(5, $ex->concurrency());
    }

    public function test_constructor_validates_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedExecutorService(0);
    }

    public function test_negative_queue_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedExecutorService(1, -1);
    }

    public function test_remaining_capacity_is_unbounded_by_default(): void
    {
        self::assertSame(PHP_INT_MAX, new FixedExecutorService(3)->remainingCapacity());
    }

    public function test_remaining_capacity_reflects_a_bounded_queue(): void
    {
        // N + Q available when idle.
        self::assertSame(3 + 5, new FixedExecutorService(3, 5)->remainingCapacity());
    }

    public function test_idle_gauges_are_zero(): void
    {
        $ex = new FixedExecutorService(4);
        self::assertSame(0, $ex->activeCount());
        self::assertSame(0, $ex->queuedCount());
    }

    public function test_submit_runs_the_task_and_returns_its_value(): void
    {
        $ex = new FixedExecutorService(2);
        self::assertSame(42, $ex->submit(static fn(): int => 42)->get());
    }

    public function test_submit_forwards_arguments(): void
    {
        $ex = new FixedExecutorService(2);
        self::assertSame(42, $ex->submit(static fn(int $x): int => $x + 1, 41)->get());
    }

    public function test_execute_runs_on_drain(): void
    {
        $ex = new FixedExecutorService(2);
        $box = new ArrayObject(['n' => 0]);

        $ex->execute(static function () use ($box): void {
            $box['n']++;
        });
        $ex->awaitTermination();

        self::assertSame(1, $box['n']);
    }

    public function test_shutdown_refuses_new_tasks(): void
    {
        $ex = new FixedExecutorService(2);
        $ex->shutdown();

        self::assertTrue($ex->isShutdown());
        $this->expectException(RejectedExecutionException::class);
        $ex->submit(static fn(): int => 1);
    }

    public function test_reject_policy_does_not_reject_when_queue_is_unbounded(): void
    {
        // Sanity: with the default unbounded queue, nothing is ever rejected even
        // under the sequential backend — the value comes straight back.
        $ex = new FixedExecutorService(1, 0, RejectPolicy::ABORT);
        self::assertSame(7, $ex->submit(static fn(): int => 7)->get());
    }
}
