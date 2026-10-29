<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Http\Health\Health;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * What the built-in components of `/actuator/health` decide, and when.
 *
 * A probe acts on `down`: a 503 takes the instance out of rotation. So `down` is kept for
 * an application that is about to stop working — a disk with no room left — and not for
 * one that is merely filling up, or for the one worker that answered the probe running
 * high on memory.
 */
final class HealthThresholdsTest extends TestCase
{
    protected function setUp(): void
    {
        new ReflectionProperty(Health::class, 'config')->setValue(null, null);
        new ReflectionProperty(Health::class, 'contributors')->setValue(null, []);
        new ReflectionProperty(HealthIndicator::class, 'checked')->setValue(null, []);
        Health::setRootDir('');
        Container::init();
    }

    /** `up` with nothing checked said something that was not true. */
    public function test_a_datasource_the_application_does_not_have_is_not_reported(): void
    {
        $components = (new HealthIndicator())->health()['components'];

        self::assertArrayNotHasKey('db', $components);
        self::assertArrayNotHasKey('redis', $components);
        self::assertSame(['disk', 'memory'], array_keys($components));
    }

    public function test_an_ordinary_disk_and_memory_are_up(): void
    {
        $components = (new HealthIndicator())->health()['components'];

        self::assertSame('up', $components['memory']['status']);
        self::assertContains($components['disk']['status'], ['up', 'degraded'], 'this machine may be full');
        self::assertArrayHasKey('path', $components['disk']['details']);
    }

    public function test_a_disk_without_room_left_is_down(): void
    {
        $disk = (new NoRoomIndicator())->health()['components']['disk'];

        self::assertSame('down', $disk['status']);
        self::assertStringStartsWith('Less than', $disk['details']['warning']);
    }

    public function test_a_disk_filling_up_is_degraded_not_down(): void
    {
        $disk = (new FillingDiskIndicator())->health()['components']['disk'];

        self::assertSame('degraded', $disk['status']);
        self::assertSame('Disk usage above 0%', $disk['details']['warning']);
    }

    /** One worker high on memory must not take the whole instance out of rotation. */
    public function test_memory_is_at_worst_degraded(): void
    {
        $report = (new HighMemoryIndicator())->health();

        self::assertSame('degraded', $report['components']['memory']['status']);
        self::assertNotSame('down', $report['status']);
    }

    /** A probe every few seconds used to open a connection to every datasource each time. */
    public function test_a_datasource_check_is_reused_within_its_window(): void
    {
        $runs = 0;
        $check = static function () use (&$runs): array {
            $runs++;
            return ['status' => 'up', 'details' => ['run' => $runs]];
        };
        $cached = new ReflectionMethod(HealthIndicator::class, 'cached');

        $first = $cached->invoke(null, 'db', $check);
        $second = $cached->invoke(null, 'db', $check);

        self::assertSame(1, $runs);
        self::assertSame($first, $second);
        $cached->invoke(null, 'redis', $check);
        self::assertSame(2, $runs, 'each component has its own entry');
    }

    public function test_an_expired_check_runs_again(): void
    {
        $runs = 0;
        $check = static function () use (&$runs): array {
            $runs++;
            return ['status' => 'up', 'details' => []];
        };
        new ReflectionProperty(HealthIndicator::class, 'checked')
            ->setValue(null, ['db' => ['at' => microtime(true) - 60, 'result' => ['stale']]]);

        $result = new ReflectionMethod(HealthIndicator::class, 'cached')->invoke(null, 'db', $check);

        self::assertSame(1, $runs);
        self::assertSame(['status' => 'up', 'details' => []], $result);
    }

    /** The thresholds are `protected const` to be overridden — the latency one included. */
    public function test_a_subclass_sets_its_own_latency_threshold(): void
    {
        $status = new ReflectionMethod(HealthIndicator::class, 'pingStatus');
        $answer = ['status' => true, 'latency' => 50];

        self::assertSame('up', $status->invoke(new HealthIndicator(), $answer));
        self::assertSame('degraded', $status->invoke(new ImpatientIndicator(), $answer));
        self::assertSame('down', $status->invoke(new ImpatientIndicator(), ['status' => false]));
    }

    /** The message may name the host, the user or the database: shown only with DEBUG. */
    public function test_a_connection_error_is_shown_only_in_debug(): void
    {
        $error = new ReflectionMethod(HealthIndicator::class, 'connectionError');
        $had = $_ENV['DEBUG'] ?? null;
        try {
            $_ENV['DEBUG'] = 'false';
            self::assertSame('connection failed', $error->invoke(null, 'App\\Db', 'could not connect to db.internal:5432 as admin'));
            $_ENV['DEBUG'] = 'true';
            self::assertSame('could not connect to db.internal:5432 as admin', $error->invoke(null, 'App\\Db', 'could not connect to db.internal:5432 as admin'));
            self::assertNull($error->invoke(null, 'App\\Db', null), 'no error, no field');
        } finally {
            if ($had === null) {
                unset($_ENV['DEBUG']);
            } else {
                $_ENV['DEBUG'] = $had;
            }
        }
    }
}

/** Any disk has less than this free. */
final class NoRoomIndicator extends HealthIndicator
{
    protected const int DISK_DOWN_FREE_BYTES = PHP_INT_MAX;
}

final class FillingDiskIndicator extends HealthIndicator
{
    protected const int DISK_DEGRADED_PERCENT = 0;
    protected const int DISK_DOWN_FREE_BYTES = 0;
}

final class HighMemoryIndicator extends HealthIndicator
{
    protected const int MEMORY_DEGRADED_PERCENT = 0;
}

/** Takes a datasource slower than a millisecond for degraded. */
final class ImpatientIndicator extends HealthIndicator
{
    protected const int DEGRADED_LATENCY_MS = 1;
}
