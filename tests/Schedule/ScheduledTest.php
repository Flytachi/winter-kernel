<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule;

use Flytachi\Winter\Kernel\Schedule\Scheduled;
use Flytachi\Winter\Kernel\Tests\Schedule\Fixtures\SampleScheduled;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ScheduledTest extends TestCase
{
    public function test_defaults_are_all_unset(): void
    {
        $s = new Scheduled();
        self::assertNull($s->fixedDelay);
        self::assertNull($s->fixedRate);
        self::assertNull($s->cron);
        self::assertSame(0.0, $s->initialDelay);
    }

    public function test_named_arguments(): void
    {
        $s = new Scheduled(fixedRate: 2.0, initialDelay: 10.0);
        self::assertSame(2.0, $s->fixedRate);
        self::assertSame(10.0, $s->initialDelay);
        self::assertNull($s->fixedDelay);
    }

    public function test_attribute_is_readable_from_a_method(): void
    {
        $method = new ReflectionMethod(SampleScheduled::class, 'onRate');
        $attrs = $method->getAttributes(Scheduled::class);
        self::assertCount(1, $attrs);

        /** @var Scheduled $inst */
        $inst = $attrs[0]->newInstance();
        self::assertSame(2.0, $inst->fixedRate);
        self::assertSame(1.5, $inst->initialDelay);
    }
}
