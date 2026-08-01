<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Pool;

use Flytachi\Winter\K2\Core\KernelConfig;
use Flytachi\Winter\K2\Core\KernelStore;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Ppa\Pool\PoolTelemetry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Telemetry over a real temp-backed runnable store: the publish → snapshot → aggregate
 * round-trip `call db pool` depends on, plus the interval knob. No server needed —
 * records are written exactly as a worker writes them.
 */
final class PoolTelemetryTest extends TestCase
{
    private string $storage = '';
    private ?string $originalPath = null;
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV['PPA_POOL_TELEMETRY'] ?? null;

        $prop = new ReflectionProperty(KernelConfig::class, 'pathStorageRunnable');
        $this->originalPath = $prop->isInitialized() ? $prop->getValue() : null;

        $this->storage = sys_get_temp_dir() . '/wk_pool_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->storage, 0777, true);
        KernelConfig::$pathStorageRunnable = $this->storage;

        // Kernel caches FileStorage by name against the path it was first built with.
        $this->clearRunnableCache();
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === null) {
            unset($_ENV['PPA_POOL_TELEMETRY']);
        } else {
            $_ENV['PPA_POOL_TELEMETRY'] = $this->originalEnv;
        }

        if ($this->originalPath !== null) {
            KernelConfig::$pathStorageRunnable = $this->originalPath;
        }
        $this->clearRunnableCache();

        foreach (glob($this->storage . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->storage . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->storage);
    }

    private function clearRunnableCache(): void
    {
        (new ReflectionProperty(KernelStore::class, 'runnable'))->setValue(null, []);
    }

    /** Writes a record exactly as a worker publishes it. */
    private function publishRecord(int $worker, array $pools, int $ttl = 60): void
    {
        Kernel::runnable('ppa.pool', false)->write(
            'worker.' . $worker,
            ['worker' => $worker, 'at' => time(), 'pools' => $pools],
            time() + $ttl,
        );
    }

    // ── interval knob ──────────────────────────────────────────────────────────

    public function test_interval_defaults_to_five_seconds(): void
    {
        unset($_ENV['PPA_POOL_TELEMETRY']);

        self::assertSame(5.0, PoolTelemetry::interval());
    }

    public function test_interval_zero_disables_telemetry(): void
    {
        $_ENV['PPA_POOL_TELEMETRY'] = '0';

        self::assertSame(0.0, PoolTelemetry::interval(), '0 turns publishing off entirely');
    }

    public function test_interval_is_floored_at_one_second(): void
    {
        $_ENV['PPA_POOL_TELEMETRY'] = '0.2';

        self::assertSame(1.0, PoolTelemetry::interval(), 'telemetry is not a heartbeat');
    }

    public function test_interval_honours_an_explicit_value(): void
    {
        $_ENV['PPA_POOL_TELEMETRY'] = '30';

        self::assertSame(30.0, PoolTelemetry::interval());
    }

    // ── store round-trip ───────────────────────────────────────────────────────

    public function test_snapshot_reads_every_worker_record_in_order(): void
    {
        $this->publishRecord(2, ['App\\Config\\MainDb' => ['total' => 1, 'idle' => 1, 'active' => 0, 'maximum' => 5]]);
        $this->publishRecord(0, ['App\\Config\\MainDb' => ['total' => 3, 'idle' => 2, 'active' => 1, 'maximum' => 5]]);

        $snapshot = PoolTelemetry::snapshot();

        self::assertCount(2, $snapshot);
        self::assertSame([0, 2], array_column($snapshot, 'worker'), 'records come back ordered by worker');
    }

    public function test_snapshot_skips_expired_records_of_dead_workers(): void
    {
        $this->publishRecord(0, ['App\\Config\\MainDb' => ['total' => 1, 'idle' => 1, 'active' => 0, 'maximum' => 5]]);
        $this->publishRecord(1, ['App\\Config\\MainDb' => ['total' => 1, 'idle' => 1, 'active' => 0, 'maximum' => 5]], -1);

        $snapshot = PoolTelemetry::snapshot();

        self::assertSame([0], array_column($snapshot, 'worker'), 'a worker that stopped refreshing simply expires');
    }

    public function test_aggregate_sums_each_config_across_workers(): void
    {
        $this->publishRecord(0, [
            'App\\Config\\MainDb' => ['total' => 5, 'idle' => 3, 'active' => 2, 'maximum' => 10],
        ]);
        $this->publishRecord(1, [
            'App\\Config\\MainDb'  => ['total' => 4, 'idle' => 1, 'active' => 3, 'maximum' => 10],
            'App\\Config\\OtherDb' => ['total' => 2, 'idle' => 2, 'active' => 0, 'maximum' => 5],
        ]);

        $aggregate = PoolTelemetry::aggregate();

        self::assertSame(
            ['total' => 9, 'idle' => 4, 'active' => 5, 'maximum' => 20, 'workers' => 2, 'saturated' => 0],
            $aggregate['App\\Config\\MainDb'],
            'the fleet view a single actuator response cannot give',
        );
        self::assertSame(
            ['total' => 2, 'idle' => 2, 'active' => 0, 'maximum' => 5, 'workers' => 1, 'saturated' => 0],
            $aggregate['App\\Config\\OtherDb'],
        );
    }

    public function test_aggregate_flags_a_single_saturated_worker(): void
    {
        // Worker 1 is fully handed out; the fleet sum (12 of 20) still looks roomy.
        $this->publishRecord(0, [
            'App\\Config\\MainDb' => ['total' => 5, 'idle' => 3, 'active' => 2, 'maximum' => 10],
        ]);
        $this->publishRecord(1, [
            'App\\Config\\MainDb' => ['total' => 10, 'idle' => 0, 'active' => 10, 'maximum' => 10],
        ]);

        $stat = PoolTelemetry::aggregate()['App\\Config\\MainDb'];

        self::assertSame(12, $stat['active']);
        self::assertSame(20, $stat['maximum']);
        self::assertSame(
            1,
            $stat['saturated'],
            'a borrow queues on its own worker, so summed slack must not hide a stalled worker',
        );
    }

    public function test_snapshot_is_empty_when_nothing_published(): void
    {
        self::assertSame([], PoolTelemetry::snapshot());
        self::assertSame([], PoolTelemetry::aggregate());
    }

    public function test_publish_writes_nothing_when_the_worker_holds_no_pool(): void
    {
        // PpaConnectionPool has no pools in this process, so there is nothing to report.
        (new ReflectionMethod(PoolTelemetry::class, 'publish'))->invoke(null, 0, 60);

        self::assertSame([], PoolTelemetry::snapshot(), 'an app that never touches PPA leaves no records');
    }
}
