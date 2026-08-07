<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus;
use Flytachi\Winter\Kernel\Process\Daemon\SlotState;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\AutoscaleLoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\BusyIdleDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\CrashCapDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\CrashLoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\HungLoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\LoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\NeverCrashDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\StuckStopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\WorkerClassDaemon;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class DaemonIntegrationTest extends IntegrationCase
{
    /** @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Daemon> $class */
    private function daemonStatus(string $class): ?DaemonStatus
    {
        $s = $class::status();
        return $s instanceof DaemonStatus ? $s : null;
    }

    public function test_forks_the_configured_replicas(): void
    {
        $sup = $this->fork(static fn() => LoopDaemon::start());

        $ready = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(LoopDaemon::class);
            return $st !== null
                && count($st->workers) === 2
                && count(array_filter($st->workers, fn($w) => $w->state === SlotState::RUNNING)) === 2;
        });
        self::assertTrue($ready, 'two workers should reach RUNNING');

        $st = $this->daemonStatus(LoopDaemon::class);
        self::assertNotNull($st);
        self::assertSame(0, $st->restarts);
        $pids = array_map(static fn($w) => $w->pid, $st->workers);
        self::assertCount(2, array_unique($pids), 'workers have distinct PIDs');
        self::assertSame($sup, $st->pid, 'status PID is the supervisor');
    }

    public function test_supervises_an_external_worker_class(): void
    {
        // $workerClass path: the daemon forks a sibling Process and boots it via
        // the protected, cross-instance runWorker() — regression guard for that.
        $sup = $this->fork(static fn() => WorkerClassDaemon::start());

        $ready = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(WorkerClassDaemon::class);
            return $st !== null
                && count(array_filter($st->workers, fn($w) => $w->state === SlotState::RUNNING)) === 2;
        });
        self::assertTrue($ready, 'the external worker class is supervised into a running fleet');

        $workerPids = array_map(static fn($w) => $w->pid, $this->daemonStatus(WorkerClassDaemon::class)->workers);
        posix_kill($sup, SIGTERM);

        self::assertTrue($this->waitExit($sup), 'the fleet stops gracefully');
        foreach ($workerPids as $pid) {
            self::assertFalse($this->isAlive($pid), "external worker {$pid} must not be orphaned");
        }
    }

    public function test_graceful_stop_drains_the_whole_fleet_without_orphans(): void
    {
        $sup = $this->fork(static fn() => LoopDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => ($st = $this->daemonStatus(LoopDaemon::class)) !== null && count($st->workers) === 2
        ));

        $workerPids = array_map(static fn($w) => $w->pid, $this->daemonStatus(LoopDaemon::class)->workers);

        posix_kill($sup, SIGTERM);

        self::assertTrue($this->waitExit($sup), 'supervisor exits gracefully');
        self::assertNull(LoopDaemon::status(), 'the store record is removed');
        foreach ($workerPids as $pid) {
            self::assertFalse($this->isAlive($pid), "worker {$pid} must not be orphaned");
        }
    }

    public function test_restarts_a_crashed_worker_in_the_same_slot(): void
    {
        $this->fork(static fn() => CrashLoopDaemon::start());

        // Restarts accumulate while the same slot (#0) is reused.
        $seen = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(CrashLoopDaemon::class);
            return $st !== null && $st->restarts >= 2;
        }, timeout: 10.0);
        self::assertTrue($seen, 'the crashed worker is restarted repeatedly');

        $st = $this->daemonStatus(CrashLoopDaemon::class);
        self::assertNotNull($st);
        foreach ($st->workers as $w) {
            self::assertSame(0, $w->slot, 'restart reuses slot #0');
        }
    }

    public function test_gives_up_after_max_restarts_and_self_terminates(): void
    {
        $sup = $this->fork(static fn() => CrashCapDaemon::start());

        // maxRestarts = 2 → the supervisor stops itself with no signal from us.
        self::assertTrue($this->waitExit($sup, 10.0), 'daemon self-terminates on FAILED');
        self::assertNull(CrashCapDaemon::status());
    }

    public function test_never_policy_retires_crashed_workers_without_restarting(): void
    {
        $this->fork(static fn() => NeverCrashDaemon::start());

        $retired = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(NeverCrashDaemon::class);
            return $st !== null
                && count($st->workers) === 2
                && count(array_filter($st->workers, fn($w) => $w->state === SlotState::RETIRED)) === 2;
        }, timeout: 8.0);

        self::assertTrue($retired, 'both crashed workers are retired terminally');
        $st = $this->daemonStatus(NeverCrashDaemon::class);
        self::assertNotNull($st, 'the daemon keeps running (no self-terminate under NEVER)');
        self::assertSame(0, $st->restarts, 'NEVER never restarts — no storm');
    }

    public function test_watchdog_kills_and_restarts_a_hung_worker(): void
    {
        $started = microtime(true);
        $sup = $this->fork(static fn() => HungLoopDaemon::start());

        // The worker is alive-but-wedged; only the watchdog can end it. With
        // livenessTimeout=2 and maxRestarts=2 it kills, restarts, then gives up —
        // so it must take at least one liveness window, not die instantly.
        self::assertTrue($this->waitExit($sup, 15.0), 'watchdog drives it to FAILED');
        self::assertGreaterThan(2.0, microtime(true) - $started, 'it waited for the liveness timeout');
    }

    public function test_autoscaler_scales_up_then_down(): void
    {
        $this->fork(static fn() => AutoscaleLoopDaemon::start());

        $scaledUp = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(AutoscaleLoopDaemon::class);
            $running = $st ? array_filter($st->workers, fn($w) => $w->state === SlotState::RUNNING) : [];
            return count($running) >= 4;
        }, timeout: 8.0);
        self::assertTrue($scaledUp, 'fleet grows to the ramped-up target');

        $scaledDown = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(AutoscaleLoopDaemon::class);
            $running = $st ? array_filter($st->workers, fn($w) => $w->state === SlotState::RUNNING) : [];
            return count($running) <= 2 && count($running) >= 1;
        }, timeout: 8.0);
        self::assertTrue($scaledDown, 'fleet shrinks after the stabilization window');
    }

    public function test_singleton_refuses_a_second_supervisor(): void
    {
        $a = $this->fork(static fn() => LoopDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => ($st = $this->daemonStatus(LoopDaemon::class)) !== null && count($st->workers) === 2
        ));

        $b = $this->fork(static fn() => LoopDaemon::start());

        // The second start is refused (already running) and exits promptly, while
        // the first keeps supervising unchanged.
        self::assertTrue($this->waitExit($b, 4.0), 'the second supervisor bails out');
        self::assertTrue($this->isAlive($a), 'the first supervisor is unaffected');
        $st = $this->daemonStatus(LoopDaemon::class);
        self::assertNotNull($st);
        self::assertSame($a, $st->pid);
        self::assertCount(2, $st->workers);
    }

    public function test_second_stop_signal_forces_a_stuck_fleet_down(): void
    {
        $sup = $this->fork(static fn() => StuckStopDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => ($st = $this->daemonStatus(StuckStopDaemon::class)) !== null && $st->workers !== []
        ));

        // First signal begins a drain the wedged worker will never honour.
        posix_kill($sup, SIGTERM);
        usleep(1_500_000);
        self::assertTrue($this->isAlive($sup), 'still draining (grace is 8s, worker is stuck)');

        // Second signal forces the fleet down at once — well before grace.
        $forcedAt = microtime(true);
        posix_kill($sup, SIGTERM);
        self::assertTrue($this->waitExit($sup, 4.0), 'the second signal forces it down');
        self::assertLessThan(6.0, microtime(true) - $forcedAt, 'forced, not waiting out the 8s grace');
    }

    public function test_per_worker_status_reports_busy_activity(): void
    {
        $this->fork(static fn() => BusyIdleDaemon::start());

        $sawBusy = $this->pollUntil(function (): bool {
            $st = $this->daemonStatus(BusyIdleDaemon::class);
            return $st !== null
                && $st->workers !== []
                && $st->workers[0]->activity === Activity::BUSY;
        }, timeout: 8.0);

        self::assertTrue($sawBusy, 'the worker heartbeat surfaces BUSY activity');
    }

    public function test_sighup_reloads_without_stopping(): void
    {
        $sup = $this->fork(static fn() => LoopDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => ($st = $this->daemonStatus(LoopDaemon::class)) !== null && count($st->workers) === 2
        ));

        posix_kill($sup, SIGHUP);
        usleep(700_000);

        self::assertTrue($this->isAlive($sup), 'SIGHUP is reload, not stop');
        $st = $this->daemonStatus(LoopDaemon::class);
        self::assertNotNull($st);
        self::assertCount(2, $st->workers, 'the fleet is untouched by reload');
    }
}
