<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\RuntimeShutdown;
use PHPUnit\Framework\TestCase;

final class RuntimeShutdownTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeShutdown::clear();
    }

    protected function tearDown(): void
    {
        RuntimeShutdown::clear();
    }

    public function test_run_all_with_no_handlers_is_a_noop(): void
    {
        RuntimeShutdown::runAll();
        $this->addToAssertionCount(1);
    }

    public function test_registered_handlers_run_in_order(): void
    {
        $order = [];
        RuntimeShutdown::register(static function () use (&$order): void {
            $order[] = 'a';
        });
        RuntimeShutdown::register(static function () use (&$order): void {
            $order[] = 'b';
        });

        RuntimeShutdown::runAll();

        self::assertSame(['a', 'b'], $order);
    }

    public function test_a_throwing_handler_does_not_block_the_others(): void
    {
        $ran = [];
        RuntimeShutdown::register(static function () use (&$ran): void {
            $ran[] = 'before';
        });
        RuntimeShutdown::register(static function (): void {
            throw new \RuntimeException('boom');
        });
        RuntimeShutdown::register(static function () use (&$ran): void {
            $ran[] = 'after';
        });

        RuntimeShutdown::runAll();

        self::assertSame(['before', 'after'], $ran);
    }

    public function test_clear_removes_all_handlers(): void
    {
        $calls = 0;
        RuntimeShutdown::register(static function () use (&$calls): void {
            $calls++;
        });

        RuntimeShutdown::clear();
        RuntimeShutdown::runAll();

        self::assertSame(0, $calls);
    }
}
