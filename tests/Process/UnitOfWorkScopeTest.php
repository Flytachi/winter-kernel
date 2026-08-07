<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[Request]
class UowJobContext
{
    public string $job = 'unset';
}

#[Singleton]
class UowSharedCounter
{
    public int $count = 0;
}

final class UowWorker extends Process
{
    public function run(): void
    {
    }

    /** markBusy() is protected — a worker body calls it, a test needs a way in. */
    public function beginUnit(): void
    {
        new ReflectionMethod(Process::class, 'markBusy')->invoke($this);
    }
}

/**
 * A worker's unit of work is also its request scope.
 *
 * Under HTTP the scope ends with the coroutine carrying the request. A worker has no
 * such boundary — its whole body runs in one coroutine, so a `#[Request]` bean resolved
 * inside it would survive every iteration and hand the previous job's state to the next.
 * Measured before the fix: four iterations, one object, each seeing what the one before
 * it wrote.
 *
 * `markBusy()` already marks where a unit begins, so it is the boundary. That keeps the
 * developer from having to know the scope exists in order to get it right.
 */
final class UnitOfWorkScopeTest extends TestCase
{
    protected function setUp(): void
    {
        Container::init();
    }

    public function test_each_unit_of_work_gets_a_fresh_request_bean(): void
    {
        $worker = new UowWorker();
        $c = Container::getInstance();

        $worker->beginUnit();
        $first = $c->make(UowJobContext::class);
        $first->job = 'job-1';

        $worker->beginUnit();
        $second = $c->make(UowJobContext::class);

        self::assertNotSame($first, $second);
        self::assertSame('unset', $second->job, "The next job must not inherit the last one's state.");
    }

    public function test_within_one_unit_the_bean_is_the_same(): void
    {
        $worker = new UowWorker();
        $c = Container::getInstance();

        $worker->beginUnit();

        self::assertSame(
            $c->make(UowJobContext::class),
            $c->make(UowJobContext::class),
            'A unit of work is one scope — that is the whole point of #[Request].',
        );
    }

    /**
     * Ending a scope is not resetting the container: a worker's singletons carry state
     * across units on purpose — a connection pool, a counter, a warm cache.
     */
    public function test_singletons_survive_the_unit_boundary(): void
    {
        $worker = new UowWorker();
        $c = Container::getInstance();

        $worker->beginUnit();
        $c->make(UowSharedCounter::class)->count = 7;

        $worker->beginUnit();

        self::assertSame(7, $c->make(UowSharedCounter::class)->count);
    }

    /**
     * A body that never marks a unit has declared no boundary, so nothing is reset —
     * the previous behaviour, kept for processes that simply run.
     */
    public function test_a_body_that_never_marks_a_unit_keeps_its_bean(): void
    {
        $c = Container::getInstance();

        $first = $c->make(UowJobContext::class);
        $first->job = 'still here';

        self::assertSame('still here', $c->make(UowJobContext::class)->job);
    }
}
