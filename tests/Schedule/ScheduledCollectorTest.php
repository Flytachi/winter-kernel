<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule;

use Flytachi\Winter\K2\Schedule\ScheduledCollector;
use Flytachi\Winter\K2\Schedule\ScheduleConfigException;
use Flytachi\Winter\K2\Schedule\Trigger\CronTrigger;
use Flytachi\Winter\K2\Schedule\Trigger\FixedDelayTrigger;
use Flytachi\Winter\K2\Schedule\Trigger\FixedRateTrigger;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\AbstractScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\ArgScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\BadCronScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\CronInitialDelayScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\CronScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\NonPositiveScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\NoTriggerScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\SampleScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\StaticScheduled;
use Flytachi\Winter\K2\Tests\Schedule\Fixtures\TwoTriggerScheduled;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ScheduledCollectorTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function collect(string $class): array
    {
        $collector = new ScheduledCollector();
        $collector->collect($class, new ReflectionClass($class));
        return $collector->getResult();
    }

    public function test_collects_annotated_methods_and_ignores_plain_ones(): void
    {
        $tasks = $this->collect(SampleScheduled::class);

        self::assertCount(2, $tasks);
        $byMethod = [];
        foreach ($tasks as $task) {
            $byMethod[$task->methodName] = $task;
        }

        self::assertArrayHasKey('onDelay', $byMethod);
        self::assertArrayHasKey('onRate', $byMethod);
        self::assertArrayNotHasKey('notScheduled', $byMethod);

        self::assertSame(SampleScheduled::class, $byMethod['onDelay']->className);
        self::assertInstanceOf(FixedDelayTrigger::class, $byMethod['onDelay']->trigger);
        self::assertSame(0.0, $byMethod['onDelay']->initialDelay);

        self::assertInstanceOf(FixedRateTrigger::class, $byMethod['onRate']->trigger);
        self::assertSame(1.5, $byMethod['onRate']->initialDelay);
    }

    public function test_no_trigger_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/no trigger/');
        $this->collect(NoTriggerScheduled::class);
    }

    public function test_two_triggers_are_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/more than one trigger/');
        $this->collect(TwoTriggerScheduled::class);
    }

    public function test_non_positive_period_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/greater than 0/');
        $this->collect(NonPositiveScheduled::class);
    }

    public function test_static_method_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/non-static/');
        $this->collect(StaticScheduled::class);
    }

    public function test_method_with_required_argument_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/no required arguments/');
        $this->collect(ArgScheduled::class);
    }

    public function test_abstract_class_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/non-instantiable/');
        $this->collect(AbstractScheduled::class);
    }

    public function test_cron_is_accepted(): void
    {
        $tasks = $this->collect(CronScheduled::class);

        self::assertCount(1, $tasks);
        self::assertInstanceOf(CronTrigger::class, $tasks[0]->trigger);
        self::assertSame('cron 0 2 * * *', $tasks[0]->trigger->describe());
    }

    public function test_malformed_cron_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/cron/');
        $this->collect(BadCronScheduled::class);
    }

    public function test_initial_delay_with_cron_is_rejected(): void
    {
        $this->expectException(ScheduleConfigException::class);
        $this->expectExceptionMessageMatches('/initialDelay is not supported with cron/');
        $this->collect(CronInitialDelayScheduled::class);
    }
}
