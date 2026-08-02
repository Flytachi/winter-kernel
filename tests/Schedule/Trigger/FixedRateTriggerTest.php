<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Trigger;

use Flytachi\Winter\Kernel\Schedule\Trigger\FixedRateTrigger;
use PHPUnit\Framework\TestCase;

final class FixedRateTriggerTest extends TestCase
{
    public function test_first_fire_is_rate_from_now(): void
    {
        $t = new FixedRateTrigger(2.0);
        self::assertSame(102.0, $t->nextFireTime(100.0, null, null));
    }

    public function test_next_fire_is_measured_from_last_start(): void
    {
        $t = new FixedRateTrigger(2.0);
        // Started at 100, finished at 100.5 (a short run): next fire is 100 + 2.
        self::assertSame(102.0, $t->nextFireTime(100.5, 100.0, 100.5));
    }

    public function test_overrun_fires_once_now_without_burst(): void
    {
        $t = new FixedRateTrigger(2.0);
        // Started at 100, still finishing at 105 (a 5s run > 2s rate): the
        // next fire is already overdue, so it fires now — not once per missed tick.
        self::assertSame(105.0, $t->nextFireTime(105.0, 100.0, 105.0));
    }

    public function test_first_fire_is_boot_plus_initial_delay(): void
    {
        $t = new FixedRateTrigger(2.0);
        self::assertSame(110.0, $t->firstFireTime(100.0, 10.0));
    }

    public function test_describe(): void
    {
        self::assertSame('fixedRate 2s', new FixedRateTrigger(2.0)->describe());
    }
}
