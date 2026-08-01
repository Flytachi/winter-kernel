<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule;

use Flytachi\Winter\K2\App\Attribute\EnableScheduler;
use Flytachi\Winter\K2\Schedule\Scheduler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards the extension point {@see EnableScheduler} advertises.
 *
 * `#[EnableScheduler(MyScheduler::class)]` accepts a `class-string<Scheduler>` so an
 * application can subclass it and source tasks its own way. Marking the class `final`
 * once silently revoked that: the subclass stopped loading, the forked scheduler died
 * without a word, and only an integration test — excluded from the default run —
 * noticed, by timing out. These assertions are cheap and fail immediately instead.
 */
final class SchedulerExtensionPointTest extends TestCase
{
    public function test_the_scheduler_can_be_subclassed(): void
    {
        self::assertFalse(
            new ReflectionClass(Scheduler::class)->isFinal(),
            'EnableScheduler documents a subclass overriding discovery, so this cannot be final',
        );
    }

    public function test_discovery_is_an_overridable_hook(): void
    {
        $discover = new ReflectionMethod(Scheduler::class, 'discover');

        self::assertTrue($discover->isProtected(), 'subclasses override discover() to source tasks');
        self::assertFalse($discover->isFinal());
    }

    public function test_the_attribute_defaults_to_the_built_in_scheduler(): void
    {
        self::assertSame(Scheduler::class, new EnableScheduler()->class);
    }
}
