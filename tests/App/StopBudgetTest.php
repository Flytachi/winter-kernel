<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;

/**
 * How long a stop waits. Every component has a bounded grace by default (30 s, the
 * usual bound); the application waits 5 seconds past the longest of them, and never
 * less than 35; the HTTP server waits for its requests as long as they may run.
 */
final class StopBudgetTest extends TestCase
{
    private static function budget(array $components): ?float
    {
        return (new \ReflectionMethod(WinterApplication::class, 'stopBudget'))->invoke(null, $components, null);
    }

    public function test_a_process_waits_30_seconds_by_default(): void
    {
        self::assertSame(30.0, (new \ReflectionClass(DefaultGraceProcess::class))->getDefaultProperties()['grace']);
    }

    public function test_the_application_waits_past_the_longest_grace(): void
    {
        self::assertSame(35.0, self::budget([Component::process(DefaultGraceProcess::class)]));
        self::assertSame(65.0, self::budget([Component::process(LongGraceProcess::class)]));
        self::assertSame(55.0, self::budget([Component::command(['sleep', '1'], 'p', grace: 50.0)]));
        self::assertSame(35.0, self::budget([Component::command(['sleep', '1'], 'p')]), 'a program has 30 s too');
    }

    public function test_a_component_that_waits_for_good_leaves_the_application_without_a_budget(): void
    {
        self::assertNull(self::budget([
            Component::process(DefaultGraceProcess::class),
            Component::process(ForeverProcess::class),
        ]));
    }

    public function test_the_http_server_waits_for_its_requests_as_long_as_they_may_run(): void
    {
        self::assertSame(30, ServerSettings::fromEnv()->toArray()['max_wait_time'], 'Swoole would cut them at 3 s');
        self::assertSame(120, ServerSettings::fromEnv()->requestTimeout(120)->toArray()['max_wait_time']);
        self::assertSame(30, ServerSettings::fromEnv()->requestTimeout(0)->toArray()['max_wait_time'], 'no deadline: the usual 30');
        self::assertSame(5, ServerSettings::fromEnv()->set('max_wait_time', 5)->toArray()['max_wait_time']);
    }
}

final class DefaultGraceProcess extends Process
{
    public function run(): void
    {
    }
}

final class LongGraceProcess extends Process
{
    protected float $grace = 60.0;

    public function run(): void
    {
    }
}

final class ForeverProcess extends Process
{
    protected float $grace = 0.0;

    public function run(): void
    {
    }
}
