<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\BeforeFork;
use PHPUnit\Framework\TestCase;

final class BeforeForkTest extends TestCase
{
    protected function setUp(): void
    {
        BeforeFork::clear();
    }

    protected function tearDown(): void
    {
        BeforeFork::clear();
    }

    public function test_run_all_with_no_handlers_is_a_noop(): void
    {
        BeforeFork::runAll();
        $this->addToAssertionCount(1);
    }

    public function test_registered_handlers_run_in_order(): void
    {
        $order = [];
        BeforeFork::register(static function () use (&$order): void {
            $order[] = 'a';
        });
        BeforeFork::register(static function () use (&$order): void {
            $order[] = 'b';
        });

        BeforeFork::runAll();

        self::assertSame(['a', 'b'], $order);
    }

    public function test_a_refusal_reaches_the_caller_after_the_others_ran(): void
    {
        // Unlike ForkReset: a handler that cannot release safely refuses the fork, and
        // the fork must not happen — so the refusal propagates. The other handlers still
        // run: what can be closed is closed either way.
        $ran = [];
        BeforeFork::register(static function () use (&$ran): void {
            $ran[] = 'before';
        });
        BeforeFork::register(static function (): void {
            throw new \RuntimeException('open transaction');
        });
        BeforeFork::register(static function () use (&$ran): void {
            $ran[] = 'after';
        });

        try {
            BeforeFork::runAll();
            self::fail('the refusal must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('open transaction', $e->getMessage());
        }
        self::assertSame(['before', 'after'], $ran);
    }

    public function test_clear_removes_all_handlers(): void
    {
        $calls = 0;
        BeforeFork::register(static function () use (&$calls): void {
            $calls++;
        });

        BeforeFork::clear();
        BeforeFork::runAll();

        self::assertSame(0, $calls);
    }
}
