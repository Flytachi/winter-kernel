<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Concurrent\Executor;

use Flytachi\Winter\Kernel\Concurrent\Executor\FixedExecutorService;
use Flytachi\Winter\Kernel\Concurrent\RejectedExecutionException;
use Flytachi\Winter\Kernel\Concurrent\RejectPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real coroutine bound — requires an active Swoole runtime, so it
 * runs inside {@see \Swoole\Coroutine\run()} and only under the `swoole` group.
 */
#[Group('swoole')]
final class FixedExecutorConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('swoole is required for the coroutine bound.');
        }
    }

    public function test_never_runs_more_than_concurrency_at_once(): void
    {
        $peak = 0;
        \Swoole\Coroutine\run(function () use (&$peak): void {
            $ex = new FixedExecutorService(2);
            $futures = [];
            for ($i = 0; $i < 6; $i++) {
                $futures[] = $ex->submit(function () use ($ex, &$peak): bool {
                    $peak = max($peak, $ex->activeCount());
                    \Swoole\Coroutine::sleep(0.02);
                    return true;
                });
            }
            foreach ($futures as $future) {
                $future->get();
            }
        });

        self::assertGreaterThan(0, $peak);
        self::assertLessThanOrEqual(2, $peak);
    }

    public function test_abort_policy_throws_when_saturated(): void
    {
        $thrown = false;
        \Swoole\Coroutine\run(function () use (&$thrown): void {
            // N=1, queue=1 → capacity for 2 in flight; the 3rd is rejected.
            $ex = new FixedExecutorService(1, 1, RejectPolicy::ABORT);
            $gate = new \Swoole\Coroutine\Channel(1);
            $block = static function () use ($gate): void {
                $gate->pop(); // hold the slot until released
            };
            $ex->submit($block); // occupies the single slot
            $ex->submit($block); // occupies the single queue slot
            try {
                $ex->submit($block); // saturated
            } catch (RejectedExecutionException) {
                $thrown = true;
            }
            $gate->push(true);
            $gate->push(true);
        });

        self::assertTrue($thrown, 'a saturated ABORT pool must reject');
    }

    public function test_discard_policy_returns_a_cancelled_future(): void
    {
        $cancelled = false;
        \Swoole\Coroutine\run(function () use (&$cancelled): void {
            $ex = new FixedExecutorService(1, 1, RejectPolicy::DISCARD);
            $gate = new \Swoole\Coroutine\Channel(1);
            $block = static function () use ($gate): void {
                $gate->pop();
            };
            $ex->submit($block);
            $ex->submit($block);
            $dropped = $ex->submit($block); // saturated → discarded
            $cancelled = $dropped->isCancelled();
            $gate->push(true);
            $gate->push(true);
        });

        self::assertTrue($cancelled, 'a discarded task must come back cancelled');
    }

    public function test_caller_runs_policy_executes_inline(): void
    {
        $ran = false;
        \Swoole\Coroutine\run(function () use (&$ran): void {
            $ex = new FixedExecutorService(1, 1, RejectPolicy::CALLER_RUNS);
            $gate = new \Swoole\Coroutine\Channel(1);
            $block = static function () use ($gate): void {
                $gate->pop();
            };
            $ex->submit($block);
            $ex->submit($block);
            $ex->submit(static function () use (&$ran): void {
                $ran = true; // runs inline, right here
            });
            $gate->push(true);
            $gate->push(true);
        });

        self::assertTrue($ran, 'CALLER_RUNS must execute the task inline');
    }
}
