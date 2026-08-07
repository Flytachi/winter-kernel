<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Daemon;

use Flytachi\Winter\Kernel\Process\Daemon\RestartMode;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use PHPUnit\Framework\TestCase;

final class RestartPolicyTest extends TestCase
{
    public function test_default_is_on_failure_unlimited_one_second_backoff(): void
    {
        $p = RestartPolicy::default();
        self::assertSame(RestartMode::ON_FAILURE, $p->mode);
        self::assertSame(0, $p->maxRestarts);
        self::assertSame(1.0, $p->backoff);
    }

    public function test_constructor_defaults_match_default_factory(): void
    {
        $p = new RestartPolicy();
        self::assertSame(RestartMode::ON_FAILURE, $p->mode);
        self::assertSame(0, $p->maxRestarts);
        self::assertSame(1.0, $p->backoff);
    }

    public function test_named_construction_and_field_types(): void
    {
        $p = new RestartPolicy(mode: RestartMode::ALWAYS, maxRestarts: 5, backoff: 2.5);
        self::assertSame(RestartMode::ALWAYS, $p->mode);
        self::assertSame(5, $p->maxRestarts);
        self::assertSame(2.5, $p->backoff);
        self::assertIsInt($p->maxRestarts);
        self::assertIsFloat($p->backoff);
    }

    public function test_should_restart_delegates_to_mode(): void
    {
        self::assertTrue((new RestartPolicy(mode: RestartMode::ALWAYS))->shouldRestart(false));
        self::assertTrue((new RestartPolicy(mode: RestartMode::ON_FAILURE))->shouldRestart(true));
        self::assertFalse((new RestartPolicy(mode: RestartMode::ON_FAILURE))->shouldRestart(false));
        self::assertFalse((new RestartPolicy(mode: RestartMode::NEVER))->shouldRestart(true));
    }

    public function test_is_readonly(): void
    {
        self::assertTrue((new \ReflectionClass(RestartPolicy::class))->isReadOnly());
    }

    public function test_is_not_final_so_named_profiles_can_subclass(): void
    {
        self::assertFalse((new \ReflectionClass(RestartPolicy::class))->isFinal());
    }
}
