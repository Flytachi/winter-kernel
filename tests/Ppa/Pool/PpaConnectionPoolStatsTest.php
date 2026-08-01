<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Pool;

use Flytachi\Winter\K2\ConnectionPool\ConnectionPool;
use Flytachi\Winter\K2\ConnectionPool\PoolPolicy;
use Flytachi\Winter\K2\Http\Health\HealthIndicator;
use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Tests\ConnectionPool\MockFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pool observability: PpaConnectionPool::stats() surfaces live per-config utilisation,
 * and HealthIndicator's `pool` component turns it into the actuator report (flagging
 * saturated pools as degraded). Pools are injected via reflection so the checks are
 * deterministic without a live database — a ConnectionPool allocates its Channel
 * eagerly, so it can be built (and read) outside a coroutine as long as it is never
 * borrowed from.
 */
final class PpaConnectionPoolStatsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ConnectionPool needs the Swoole Channel.');
        }
    }

    /** @param array<string, ConnectionPool> $pools */
    private function withPools(array $pools, callable $body): void
    {
        $prop = new ReflectionProperty(PpaConnectionPool::class, 'pools');
        $original = $prop->getValue();
        try {
            $prop->setValue(null, $pools);
            $body();
        } finally {
            $prop->setValue(null, $original);
        }
    }

    private static function fullPool(int $maximum): ConnectionPool
    {
        $pool = new ConnectionPool(new MockFactory(), new PoolPolicy(maximumPoolSize: $maximum));
        // Simulate every connection handed out (active == maximum, none idle).
        (new ReflectionProperty(ConnectionPool::class, 'total'))->setValue($pool, $maximum);
        return $pool;
    }

    public function test_stats_keys_by_config_fqcn(): void
    {
        $pool = new ConnectionPool(new MockFactory(), new PoolPolicy(maximumPoolSize: 7));
        $this->withPools([base64_encode('App\\Config\\MainDb') => $pool], function (): void {
            self::assertSame(
                ['App\\Config\\MainDb' => ['total' => 0, 'idle' => 0, 'active' => 0, 'maximum' => 7]],
                PpaConnectionPool::stats(),
            );
        });
    }

    public function test_pool_health_reports_up_when_slack_available(): void
    {
        $pool = new ConnectionPool(new MockFactory(), new PoolPolicy(maximumPoolSize: 5));
        $this->withPools([base64_encode('App\\Config\\MainDb') => $pool], function (): void {
            $component = (new ReflectionMethod(HealthIndicator::class, 'poolHealth'))
                ->invoke(new HealthIndicator());

            self::assertSame('up', $component['status']);
            self::assertSame('up', $component['details']['App\\Config\\MainDb']['status']);
            self::assertSame(5, $component['details']['App\\Config\\MainDb']['maximum']);
        });
    }

    public function test_pool_health_flags_saturated_pool_as_degraded(): void
    {
        $this->withPools(
            [
                base64_encode('App\\Config\\MainDb')  => self::fullPool(2),
                base64_encode('App\\Config\\OtherDb') => new ConnectionPool(new MockFactory(), new PoolPolicy(maximumPoolSize: 5)),
            ],
            function (): void {
                $component = (new ReflectionMethod(HealthIndicator::class, 'poolHealth'))
                    ->invoke(new HealthIndicator());

                self::assertSame('degraded', $component['status'], 'a saturated pool degrades the whole component');
                self::assertSame('degraded', $component['details']['App\\Config\\MainDb']['status']);
                self::assertSame(2, $component['details']['App\\Config\\MainDb']['active']);
                self::assertSame('up', $component['details']['App\\Config\\OtherDb']['status']);
            },
        );
    }

    public function test_pool_health_is_up_when_no_pools(): void
    {
        $this->withPools([], function (): void {
            $component = (new ReflectionMethod(HealthIndicator::class, 'poolHealth'))
                ->invoke(new HealthIndicator());

            self::assertSame('up', $component['status']);
            self::assertSame([], $component['details']);
        });
    }
}
