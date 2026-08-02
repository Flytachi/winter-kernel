<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Pool;

use Flytachi\Winter\Kernel\Ppa\Pool\PpaPoolTrait;
use PHPUnit\Framework\TestCase;

/**
 * PpaPoolTrait supplies safe defaults for every pool knob (so a config that only sets
 * some of them, or none, never fatals) and honours property overrides.
 */
final class PpaPoolTraitTest extends TestCase
{
    public function test_defaults_when_no_properties_declared(): void
    {
        $config = new class {
            use PpaPoolTrait;
        };

        self::assertSame(5, $config->getPoolMaxConnections());
        self::assertSame(3.0, $config->getPoolWaitTimeout());
        self::assertSame(0.0, $config->getKeepaliveTime(), 'housekeeping off by default');
        self::assertSame(0.0, $config->getIdleTimeout(), 'no shrink by default');
        self::assertSame(0, $config->getMinimumIdle(), 'fully lazy by default');
    }

    public function test_property_overrides(): void
    {
        $config = new class {
            use PpaPoolTrait;

            public int $poolMaxConnections = 20;
            public float $poolWaitTimeout = 5.0;
            public float $keepaliveTime = 30.0;
            public float $idleTimeout = 600.0;
            public int $minimumIdle = 4;
        };

        self::assertSame(20, $config->getPoolMaxConnections());
        self::assertSame(5.0, $config->getPoolWaitTimeout());
        self::assertSame(30.0, $config->getKeepaliveTime());
        self::assertSame(600.0, $config->getIdleTimeout());
        self::assertSame(4, $config->getMinimumIdle());
    }
}
