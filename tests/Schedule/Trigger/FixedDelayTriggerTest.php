<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Trigger;

use Flytachi\Winter\Kernel\Schedule\Trigger\FixedDelayTrigger;
use PHPUnit\Framework\TestCase;

final class FixedDelayTriggerTest extends TestCase
{
    public function test_first_fire_is_delay_from_now(): void
    {
        $t = new FixedDelayTrigger(5.0);
        // Before the first run there is no last end; measure from now.
        self::assertSame(105.0, $t->nextFireTime(100.0, null, null));
    }

    public function test_next_fire_is_measured_from_last_end_not_start(): void
    {
        $t = new FixedDelayTrigger(5.0);
        // Started at 100, finished at 108 (an 8s run): the next fire is 108 + 5.
        self::assertSame(113.0, $t->nextFireTime(108.0, 100.0, 108.0));
    }

    public function test_first_fire_is_boot_plus_initial_delay(): void
    {
        $t = new FixedDelayTrigger(5.0);
        self::assertSame(110.0, $t->firstFireTime(100.0, 10.0));
        self::assertSame(100.0, $t->firstFireTime(100.0, 0.0));
    }

    public function test_describe(): void
    {
        self::assertSame('fixedDelay 5s', new FixedDelayTrigger(5.0)->describe());
    }
}
