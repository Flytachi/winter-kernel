<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRecord;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\App\Supervision\ComponentControl;
use Flytachi\Winter\Kernel\Process\ProcessManagedException;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\CrashingProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\DispatchingProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\FinishingProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\FixableProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * While the application runs, it starts every process. One it was not declared with is
 * taken in for one run: never restarted, never critical, let go when it ends. A declared
 * process that finished its work leaves no record and can be started again. With the
 * application in another environment, nothing is started here.
 */
#[Group('integration')]
final class AdoptionIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = $this->storage . '/marker';
        putenv('WK_MARKER=' . $this->marker);
        putenv('WK_BROKEN=' . $this->storage . '/broken');
        $classes = [SignalProcess::class, FinishingProcess::class, CrashingProcess::class, FixableProcess::class, DispatchingProcess::class];
        foreach ($classes as $class) {
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

    public function test_a_process_not_declared_is_taken_in_and_let_go_when_it_ends(): void
    {
        $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $this->awaitRunning(SignalProcess::class);

        FinishingProcess::dispatch();

        self::assertTrue($this->pollUntil(fn() => $this->marks('finishing run') === 1));
        self::assertTrue($this->waitLogged('FinishingProcess finished its work.'));
        self::assertStringContainsString('FinishingProcess started on request: not declared', $this->logged());
        self::assertTrue($this->pollUntil(fn() => !isset($this->units()['FinishingProcess'])), 'let go');
        self::assertNull(FinishingProcess::status(), 'nothing of it is kept');
    }

    public function test_a_process_taken_in_that_crashes_is_not_restarted_and_the_application_runs_on(): void
    {
        $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $first = $this->awaitRunning(SignalProcess::class);

        CrashingProcess::dispatch();

        self::assertTrue($this->pollUntil(fn() => ($this->units()['CrashingProcess']['state'] ?? null) === 'Failed'));
        $unit = $this->units()['CrashingProcess'];
        self::assertTrue($unit['adopted']);
        self::assertFalse($unit['critical'], 'never critical');
        self::assertNotSame('', $unit['reason']);
        usleep(1_500_000);
        self::assertSame(1, $this->marks('crashing run'), 'not restarted');
        self::assertSame($first, SignalProcess::status()?->pid, 'the application runs on');
        self::assertNull(CrashingProcess::status(), 'its class keeps no record');

        CrashingProcess::dispatch();
        self::assertTrue($this->pollUntil(fn() => $this->marks('crashing run') === 2), 'taken in afresh');
    }

    public function test_a_process_taken_in_and_stopped_is_let_go(): void
    {
        $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        $this->awaitRunning(SignalProcess::class);

        $pid = FixableProcess::dispatch();
        self::assertGreaterThan(0, $pid);
        self::assertTrue($this->pollUntil(fn() => ($this->units()['FixableProcess']['state'] ?? null) === 'Running'));
        self::assertSame($pid, $this->units()['FixableProcess']['pid']);

        self::assertTrue(FixableProcess::stop());

        self::assertTrue($this->pollUntil(fn() => !isset($this->units()['FixableProcess'])));
        self::assertNull(FixableProcess::status());
    }

    public function test_a_declared_process_that_finished_leaves_no_record_and_starts_again(): void
    {
        $this->supervise([
            Component::process(SignalProcess::class),
            Component::process(FinishingProcess::class),
        ], asApplication: true);
        self::assertTrue($this->pollUntil(fn() => ($this->units()['FinishingProcess']['state'] ?? null) === 'Completed'));
        self::assertNull(FinishingProcess::status(), 'no record says it is down');

        FinishingProcess::dispatch();

        self::assertTrue($this->pollUntil(fn() => $this->marks('finishing run') === 2));
        self::assertTrue($this->pollUntil(fn() => ($this->units()['FinishingProcess']['state'] ?? null) === 'Completed'));
        self::assertFalse($this->units()['FinishingProcess']['adopted'], 'still the declared one');
    }

    public function test_a_process_started_from_inside_the_application_is_taken_in(): void
    {
        $this->supervise([Component::process(DispatchingProcess::class)], asApplication: true);

        self::assertTrue($this->pollUntil(fn() => $this->marks('finishing run') === 1));
        self::assertTrue($this->waitLogged('FinishingProcess finished its work.'), 'run by the application');
    }

    public function test_nothing_starts_here_while_the_application_runs_in_another_environment(): void
    {
        $this->actAsApplication(self::$testApp);
        $this->foreignApplication(seenAt: time());

        try {
            FinishingProcess::dispatch();
            self::fail('started beside the application');
        } catch (ProcessManagedException $e) {
            self::assertStringContainsString('The application runs in another environment (another-container)', $e->getMessage());
        }
        self::assertFalse(FinishingProcess::relaunch());
    }

    public function test_an_application_in_another_environment_that_went_silent_is_a_leftover(): void
    {
        $this->actAsApplication(self::$testApp);
        $this->foreignApplication(seenAt: time() - ApplicationRuntime::HEARTBEAT_TTL - 5);

        self::assertNull(ApplicationRuntime::servingElsewhere());
        (new \ReflectionMethod(FinishingProcess::class, 'ensureNotRunning'))->invoke(null);
        $this->addToAssertionCount(1);
    }

    private function foreignApplication(int $seenAt): void
    {
        $store = (new \ReflectionMethod(ApplicationRuntime::class, 'store'))->invoke(null);
        $key = str_replace('\\', '.', self::$testApp);
        $store->write($key, new ApplicationRecord(1, self::$testApp, time() - 60, 'another-container', true, []));
        $store->write($key . '.units', ['seenAt' => $seenAt, 'units' => []]);
    }

    /** @return array<string, array<string, mixed>> */
    private function units(): array
    {
        return array_column(ApplicationRuntime::units(self::$testApp), null, 'name');
    }

    /** How many marks start with $line. */
    private function marks(string $line): int
    {
        $lines = @file($this->marker, FILE_IGNORE_NEW_LINES) ?: [];
        return count(array_filter($lines, static fn(string $l): bool => str_starts_with($l, $line)));
    }

    private function awaitRunning(string $class): int
    {
        self::assertTrue($this->pollUntil(fn() => $class::status()?->state === ProcessState::RUNNING));
        return $class::status()->pid;
    }
}
