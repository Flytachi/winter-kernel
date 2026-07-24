<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Daemon;

use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\DaemonConfigException;
use Flytachi\Winter\K2\Process\Daemon\RestartMode;
use Flytachi\Winter\K2\Process\Daemon\RestartPolicy;
use Flytachi\Winter\K2\Process\Daemon\ScalingPolicy;
use Flytachi\Winter\K2\Tests\Process\Fixtures\BlankDaemon;
use Flytachi\Winter\K2\Tests\Process\Fixtures\ClampDaemon;
use Flytachi\Winter\K2\Tests\Process\Fixtures\DefaultDaemon;
use Flytachi\Winter\K2\Tests\Process\Fixtures\ExternalDaemon;
use Flytachi\Winter\K2\Tests\Process\Fixtures\InlineDaemon;
use PHPUnit\Framework\TestCase;

final class DaemonTest extends TestCase
{
    private function priv(object $o, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($o, $method))->invoke($o, ...$args);
    }

    // --- introspection accessors + clamping -------------------------------

    // These accessors are internal (consumed by the SupervisesFleet trait), so
    // they are reflected — clamping behaviour is still asserted directly.

    public function test_replicas_clamps_to_at_least_one(): void
    {
        self::assertSame(1, $this->priv(new DefaultDaemon(), 'replicas'));
        self::assertSame(3, $this->priv(new InlineDaemon(), 'replicas'));
        self::assertSame(1, $this->priv(new ClampDaemon(), 'replicas'));   // configured 0 → 1
    }

    public function test_compute_desired_clamps_to_at_least_zero(): void
    {
        self::assertSame(1, $this->priv(new DefaultDaemon(), 'computeDesired'));   // = replicas()
        self::assertSame(4, $this->priv(new InlineDaemon(), 'computeDesired'));
        self::assertSame(0, $this->priv(new ClampDaemon(), 'computeDesired'));     // configured -3 → 0
    }

    public function test_grace_seconds_clamps_and_defaults_to_thirty(): void
    {
        self::assertSame(30.0, $this->priv(new DefaultDaemon(), 'graceSeconds'));  // k8s-parity default
        self::assertSame(5.0, $this->priv(new InlineDaemon(), 'graceSeconds'));
        self::assertSame(0.0, $this->priv(new ClampDaemon(), 'graceSeconds'));     // configured -5 → 0
    }

    public function test_liveness_timeout_clamps_and_defaults_to_off(): void
    {
        self::assertSame(0.0, $this->priv(new DefaultDaemon(), 'livenessTimeout'));  // watchdog off
        self::assertSame(7.0, $this->priv(new InlineDaemon(), 'livenessTimeout'));
        self::assertSame(0.0, $this->priv(new ClampDaemon(), 'livenessTimeout'));    // configured -1 → 0
    }

    // --- policies ----------------------------------------------------------

    public function test_default_scaling_policy(): void
    {
        $p = $this->priv(new DefaultDaemon(), 'scalingPolicy');
        self::assertInstanceOf(ScalingPolicy::class, $p);
        self::assertEquals(ScalingPolicy::default(), $p);
    }

    public function test_overridden_scaling_policy(): void
    {
        $p = $this->priv(new InlineDaemon(), 'scalingPolicy');
        self::assertSame(2.0, $p->scaleInterval);
        self::assertSame(2, $p->scaleStep);
    }

    public function test_default_restart_policy(): void
    {
        $p = $this->priv(new DefaultDaemon(), 'restartPolicy');
        self::assertInstanceOf(RestartPolicy::class, $p);
        self::assertSame(RestartMode::ON_FAILURE, $p->mode);
        self::assertSame(0, $p->maxRestarts);
    }

    public function test_overridden_restart_policy(): void
    {
        $p = $this->priv(new InlineDaemon(), 'restartPolicy');
        self::assertSame(RestartMode::ALWAYS, $p->mode);
        self::assertSame(4, $p->maxRestarts);
        self::assertSame(0.25, $p->backoff);
    }

    // --- worker body resolution -------------------------------------------

    public function test_defines_worker_run_true_when_overridden(): void
    {
        self::assertTrue($this->priv(new InlineDaemon(), 'definesWorkerRun'));
        self::assertTrue($this->priv(new DefaultDaemon(), 'definesWorkerRun'));
    }

    public function test_defines_worker_run_false_when_using_worker_class(): void
    {
        self::assertFalse($this->priv(new ExternalDaemon(), 'definesWorkerRun'));
    }

    public function test_boot_worker_throws_when_no_body_configured(): void
    {
        $this->expectException(DaemonConfigException::class);
        $this->priv(new BlankDaemon(), 'bootWorker', 0);
    }

    public function test_run_delegates_to_worker_run_and_throws_when_undefined(): void
    {
        // BlankDaemon has no workerRun → the base default throws.
        $this->expectException(DaemonConfigException::class);
        (new BlankDaemon())->run();
    }

    public function test_run_delegates_to_defined_worker_run_without_throwing(): void
    {
        // InlineDaemon defines an empty workerRun → run() is a clean no-op.
        (new InlineDaemon())->run();
        $this->addToAssertionCount(1);
    }

    public function test_run_is_final(): void
    {
        self::assertTrue((new \ReflectionMethod(Daemon::class, 'run'))->isFinal());
    }

    // --- titles ------------------------------------------------------------

    public function test_master_title(): void
    {
        self::assertSame('winter-daemon: DefaultDaemon master', $this->priv(new DefaultDaemon(), 'buildProcessTitle'));
    }

    public function test_worker_title_is_one_based(): void
    {
        $d = new DefaultDaemon();
        self::assertSame('winter-daemon: DefaultDaemon worker#1', $this->priv($d, 'workerTitle', 0));
        self::assertSame('winter-daemon: DefaultDaemon worker#3', $this->priv($d, 'workerTitle', 2));
    }

    // --- hook fan-out ------------------------------------------------------

    public function test_fire_hooks_invoke_the_protected_callbacks(): void
    {
        $d = new InlineDaemon();

        $this->priv($d, 'fireWorkerStart', 0, 111);
        $this->priv($d, 'fireWorkerStart', 1, 222);
        $this->priv($d, 'fireWorkerExit', 1, 222, true);
        $this->priv($d, 'fireScale', 2, 5);
        $this->priv($d, 'fireTick');
        $this->priv($d, 'fireTick');
        $this->priv($d, 'fireReload');

        self::assertSame([[0, 111], [1, 222]], $d->started);
        self::assertSame([[1, 222, true]], $d->exited);
        self::assertSame([[2, 5]], $d->scaled);
        self::assertSame(2, $d->ticks);
        self::assertSame(1, $d->reloads);
    }
}
