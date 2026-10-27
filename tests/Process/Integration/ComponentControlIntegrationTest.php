<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\App\Runtime\ManagedBy;
use Flytachi\Winter\Kernel\App\Supervision\ComponentControl;
use Flytachi\Winter\Kernel\App\Supervision\ControlRequest;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\ProcessManagedException;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\FixableProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component of the running application is the application's to start, stop and
 * restart — from code in any of its processes, as from the console. Stopped, it stays
 * down until it is started again; started again, it starts afresh. A process outside the
 * application that holds a component is waited for, not fought, and a record written in
 * another environment is left alone.
 */
#[Group('integration')]
final class ComponentControlIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('WK_MARKER=' . $this->storage . '/marker');
        putenv('WK_BROKEN=' . $this->storage . '/broken');
        foreach ([SignalProcess::class, FixableProcess::class] as $class) {
            (new \ReflectionMethod($class, 'store'))->invoke(null);
        }
        ApplicationRuntime::record(self::$testApp);
        (new \ReflectionMethod(ComponentControl::class, 'store'))->invoke(null);
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        putenv('WK_BROKEN');
        $this->actAsApplication(null);
        parent::tearDown();
    }

    public function test_a_stopped_component_stays_down_until_dispatched_again(): void
    {
        $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $first = $this->awaitRunning(SignalProcess::class);

        self::assertTrue(SignalProcess::stop());
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->state === ProcessState::TERMINATED));
        self::assertNotNull(SignalProcess::status()->managedBy, 'down, and the application says so');
        usleep(1_500_000);
        self::assertSame(ProcessState::TERMINATED, SignalProcess::status()->state, 'not restarted');

        try {
            SignalProcess::start();
            self::fail('a component of the running application is not started here');
        } catch (ProcessManagedException $e) {
            self::assertStringContainsString('is a component of the running application', $e->getMessage());
        }

        $second = SignalProcess::dispatch();
        self::assertGreaterThan(0, $second, 'started by the application, its PID returned');
        self::assertNotSame($first, $second);
        self::assertSame($second, SignalProcess::status()?->pid);
        self::assertStringContainsString('SignalProcess started on request.', $this->logged());
    }

    public function test_the_supervisor_publishes_where_every_component_stands(): void
    {
        touch($this->storage . '/broken');
        $this->supervise([
            Component::process(SignalProcess::class),
            Component::process(FixableProcess::class, critical: false),
        ], asApplication: true);
        $pid = $this->awaitRunning(SignalProcess::class);

        $byName = fn(): array => array_column(ApplicationRuntime::units(self::$testApp), null, 'name');
        self::assertTrue($this->pollUntil(fn() => ($byName()['FixableProcess']['state'] ?? null) === 'Failed'));

        $units = $byName();
        self::assertSame(
            ['kind' => 'Process', 'state' => 'Running', 'pid' => $pid, 'critical' => true],
            array_intersect_key($units['SignalProcess'], array_flip(['kind', 'state', 'pid', 'critical'])),
        );
        self::assertFalse($units['FixableProcess']['critical']);
        self::assertNotEmpty($units['FixableProcess']['reason'], 'a failure says why');
        self::assertGreaterThan(0, $units['SignalProcess']['since']);

        self::assertTrue(SignalProcess::stop());
        self::assertTrue($this->pollUntil(fn() => ($byName()['SignalProcess']['state'] ?? null) === 'Stopped'));
    }

    public function test_relaunch_restarts_a_running_component_without_counting_a_crash(): void
    {
        $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $first = $this->awaitRunning(SignalProcess::class);

        self::assertTrue(SignalProcess::relaunch());

        self::assertTrue($this->pollUntil(fn() => ($pid = SignalProcess::status()?->pid) !== null && $pid !== $first));
        self::assertSame(ProcessState::RUNNING, SignalProcess::status()->state);
        self::assertStringContainsString('SignalProcess restarting on request.', $this->logged());
        self::assertStringNotContainsString('crashed', $this->logged());
    }

    public function test_a_failed_component_starts_again_once_its_fault_is_fixed(): void
    {
        touch($this->storage . '/broken');
        $this->supervise([Component::process(FixableProcess::class, critical: false)], asApplication: true);
        self::assertTrue($this->pollUntil(fn() => FixableProcess::status()?->state === ProcessState::FAILED));

        unlink($this->storage . '/broken');
        $pid = FixableProcess::dispatch();

        self::assertGreaterThan(0, $pid);
        self::assertSame(ProcessState::RUNNING, FixableProcess::status()?->state);
    }

    public function test_a_component_running_outside_the_application_is_waited_for(): void
    {
        $outsider = $this->fork(static fn() => SignalProcess::start());
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->pid === $outsider));

        $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        self::assertTrue($this->waitLogged("SignalProcess runs outside the application [PID {$outsider}]"));
        self::assertTrue($this->isAlive($supervisor), 'held, not failed');

        posix_kill($outsider, SIGTERM);

        self::assertTrue($this->waitLogged('SignalProcess is free again'));
        self::assertTrue($this->pollUntil(
            fn() => ($s = SignalProcess::status()) !== null && $s->pid !== $outsider && $s->managedBy !== null
        ), 'now the application runs it');
    }

    public function test_a_record_from_another_environment_is_left_alone(): void
    {
        $status = new ProcessStatus(posix_getppid(), SignalProcess::class, ProcessState::RUNNING, Activity::IDLE, time());
        $status->host = 'another-container';
        (new \ReflectionMethod(SignalProcess::class, 'store'))->invoke(null)
            ->write((new \ReflectionMethod(SignalProcess::class, 'key'))->invoke(null), $status);

        self::assertSame('another-container', SignalProcess::status()?->host, 'shown as it is');
        self::assertFalse(SignalProcess::stop(), 'its PID means nothing here');
        self::assertFalse(SignalProcess::relaunch());
        $this->expectException(ProcessManagedException::class);
        $this->expectExceptionMessage('runs in another environment (another-container)');
        SignalProcess::start();
    }

    public function test_a_request_for_another_run_of_the_application_is_dropped(): void
    {
        $previous = new ManagedBy(self::$testApp, 111, time() - 600, ApplicationRuntime::host());
        $current = new ManagedBy(self::$testApp, 222, time(), ApplicationRuntime::host());

        ComponentControl::request($previous, SignalProcess::class, ComponentControl::START);
        self::assertSame([], ComponentControl::pending($current), 'meant for a run that is gone');
        self::assertSame([], ComponentControl::pending($current), 'and taken away');

        ComponentControl::request($current, SignalProcess::class, ComponentControl::RESTART, ['port' => '8001']);
        self::assertEquals(
            [new ControlRequest(SignalProcess::class, ComponentControl::RESTART, ['port' => '8001'])],
            ComponentControl::pending($current),
        );
    }

    public function test_what_the_application_recorded_goes_with_it(): void
    {
        $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $this->awaitRunning(SignalProcess::class);
        SignalProcess::stop();
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->state === ProcessState::TERMINATED));

        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertNull(SignalProcess::status());
        self::assertNull(
            (new \ReflectionMethod(SignalProcess::class, 'store'))->invoke(null)
                ->read((new \ReflectionMethod(SignalProcess::class, 'key'))->invoke(null)),
            'removed, not only stale',
        );
    }

    public function test_a_stopped_record_of_a_killed_application_is_not_taken_for_its_component(): void
    {
        $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $this->awaitRunning(SignalProcess::class);
        SignalProcess::stop();
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->state === ProcessState::TERMINATED));

        posix_kill($supervisor, SIGKILL);
        self::assertTrue($this->waitExit($supervisor));

        self::assertNull(SignalProcess::status(), 'its run is gone, and so is what it said');
        $this->actAsApplication(null);
        $pid = $this->fork(static fn() => SignalProcess::start());
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->pid === $pid), 'free to start on its own');
    }

    /** @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Process> $class */
    private function awaitRunning(string $class): int
    {
        self::assertTrue($this->pollUntil(fn() => $class::status()?->state === ProcessState::RUNNING));
        return $class::status()->pid;
    }
}
