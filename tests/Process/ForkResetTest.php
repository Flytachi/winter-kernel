<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\ForkReset;
use PHPUnit\Framework\TestCase;

final class ForkResetTest extends TestCase
{
    protected function setUp(): void
    {
        ForkReset::clear();
    }

    protected function tearDown(): void
    {
        ForkReset::clear();
    }

    public function test_run_all_with_no_handlers_is_a_noop(): void
    {
        ForkReset::runAll();
        $this->addToAssertionCount(1);
    }

    public function test_registered_handlers_run_in_order(): void
    {
        $order = [];
        ForkReset::register(static function () use (&$order): void {
            $order[] = 'a';
        });
        ForkReset::register(static function () use (&$order): void {
            $order[] = 'b';
        });

        ForkReset::runAll();

        self::assertSame(['a', 'b'], $order);
    }

    public function test_a_throwing_handler_does_not_block_the_others(): void
    {
        $ran = [];
        ForkReset::register(static function () use (&$ran): void {
            $ran[] = 'before';
        });
        ForkReset::register(static function (): void {
            throw new \RuntimeException('boom');
        });
        ForkReset::register(static function () use (&$ran): void {
            $ran[] = 'after';
        });

        ForkReset::runAll();

        self::assertSame(['before', 'after'], $ran);
    }

    public function test_clear_removes_all_handlers(): void
    {
        $calls = 0;
        ForkReset::register(static function () use (&$calls): void {
            $calls++;
        });

        ForkReset::clear();
        ForkReset::runAll();

        self::assertSame(0, $calls);
    }
}
