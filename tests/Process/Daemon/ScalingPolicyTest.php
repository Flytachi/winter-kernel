<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Daemon;

use Flytachi\Winter\K2\Process\Daemon\ScalingPolicy;
use PHPUnit\Framework\TestCase;

final class ScalingPolicyTest extends TestCase
{
    public function test_default_values(): void
    {
        $p = ScalingPolicy::default();
        self::assertSame(1.0, $p->scaleInterval);
        self::assertSame(0.0, $p->scaleUpDelay);
        self::assertSame(60.0, $p->scaleDownStabilization);
        self::assertSame(3.0, $p->cooldown);
        self::assertSame(0, $p->scaleStep);
    }

    public function test_constructor_defaults_match_default_factory(): void
    {
        $a = new ScalingPolicy();
        $b = ScalingPolicy::default();
        self::assertEquals($a, $b);
    }

    public function test_named_construction_and_types(): void
    {
        $p = new ScalingPolicy(
            scaleInterval: 0.5,
            scaleUpDelay: 2.0,
            scaleDownStabilization: 120.0,
            cooldown: 10.0,
            scaleStep: 4,
        );
        self::assertSame(0.5, $p->scaleInterval);
        self::assertSame(2.0, $p->scaleUpDelay);
        self::assertSame(120.0, $p->scaleDownStabilization);
        self::assertSame(10.0, $p->cooldown);
        self::assertSame(4, $p->scaleStep);
        self::assertIsInt($p->scaleStep);
        self::assertIsFloat($p->scaleDownStabilization);
    }

    public function test_is_readonly(): void
    {
        self::assertTrue((new \ReflectionClass(ScalingPolicy::class))->isReadOnly());
    }

    public function test_is_not_final_so_named_profiles_can_subclass(): void
    {
        self::assertFalse((new \ReflectionClass(ScalingPolicy::class))->isFinal());
    }
}
