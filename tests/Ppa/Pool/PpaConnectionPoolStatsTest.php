<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Pool;

use Flytachi\Winter\Kernel\ConnectionPool\ConnectionPool;
use Flytachi\Winter\Kernel\ConnectionPool\PoolPolicy;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use Flytachi\Winter\Kernel\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Kernel\Tests\ConnectionPool\MockFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pool observability: PpaConnectionPool::stats() surfaces live per-config utilisation,
 * and HealthIndicator::pools() turns it into the `/actuator/pools` report (flagging
 * saturated pools). The `db` component of `/actuator/health` deliberately knows
 * nothing about pools. Pools are injected via reflection so the checks are
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

    /** Runs the `db` component with the scan skipped, so nothing but the shape is exercised. */
    private static function dbComponent(): array
    {
        return (new ReflectionMethod(HealthIndicator::class, 'dbHealth'))
            ->invoke(new HealthIndicator(), '');
    }

    public function test_pools_endpoint_reports_utilisation(): void
    {
        $pool = new ConnectionPool(new MockFactory(), new PoolPolicy(maximumPoolSize: 5));
        $this->withPools([base64_encode('App\\Config\\MainDb') => $pool], function (): void {
            $report = new HealthIndicator()->pools();

            self::assertSame('up', $report['status']);
            self::assertSame(
                ['total' => 0, 'idle' => 0, 'active' => 0, 'maximum' => 5, 'saturated' => false],
                $report['pools']['App\\Config\\MainDb'],
            );
        });
    }

    public function test_saturated_pool_is_flagged(): void
    {
        $this->withPools(
            [
                base64_encode('App\\Config\\MainDb')  => self::fullPool(2),
                base64_encode('App\\Config\\OtherDb') => new ConnectionPool(new MockFactory(), new PoolPolicy(maximumPoolSize: 5)),
            ],
            function (): void {
                $report = new HealthIndicator()->pools();

                self::assertSame('degraded', $report['status'], 'a saturated pool degrades the pools report');
                self::assertTrue($report['pools']['App\\Config\\MainDb']['saturated']);
                self::assertSame(2, $report['pools']['App\\Config\\MainDb']['active']);
                self::assertFalse($report['pools']['App\\Config\\OtherDb']['saturated']);
            },
        );
    }

    public function test_pools_report_is_empty_when_no_pools(): void
    {
        $this->withPools([], function (): void {
            self::assertSame(['status' => 'up', 'pools' => []], new HealthIndicator()->pools());
        });
    }

    /**
     * Saturation is a pools concern, not a health one: a busy-but-reachable
     * application must not report `degraded` to a probe that decides whether to keep
     * sending it traffic.
     */
    public function test_db_component_ignores_pools_entirely(): void
    {
        $this->withPools(
            [base64_encode('App\\Config\\MainDb') => self::fullPool(2)],
            function (): void {
                $component = self::dbComponent();

                self::assertSame('up', $component['status']);
                self::assertSame([], $component['details'], 'no pool leaks into the db component');
            },
        );
    }

}
