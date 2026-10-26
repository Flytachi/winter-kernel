<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\CrashCapDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\LoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\BrokenStartProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\BrokenWorkersDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\CrashingProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\FinishingProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\HangingStartProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * The application's supervisor with real processes: a component that breaks while it
 * starts is not retried, a crash after it was ready is retried with back-off up to its
 * limit, a daemon is never retried, and a component that cannot run stops the
 * application when it is critical and is left down when it is not. A stop on request,
 * and a process that finished its work, are not failures.
 */
#[Group('integration')]
final class SupervisorIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = $this->storage . '/marker';
        putenv('WK_MARKER=' . $this->marker);
        $classes = [SignalProcess::class, LoopDaemon::class, CrashCapDaemon::class, BrokenStartProcess::class];
        foreach ($classes as $class) {
            (new \ReflectionMethod($class, 'store'))->invoke(null);
        }
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        $this->actAsApplication(null);
        parent::tearDown();
    }

    // ── Starting ──────────────────────────────────────────────────────────────

    public function test_a_component_that_breaks_while_starting_is_not_retried_and_stops_the_application(): void
    {
        $supervisor = $this->supervise([Component::process(BrokenStartProcess::class)]);

        self::assertSame(1, $this->exitCode($supervisor), 'a critical component that cannot start stops all');
        self::assertStringContainsString('BrokenStartProcess failed: did not start: exit code 1', $this->announced());
        $reason = 'BrokenStartProcess failed — RuntimeException: cannot be built';
        self::assertSame(1, substr_count($this->reports(), $reason), 'tried once');
    }

    public function test_not_ready_within_its_start_timeout_is_a_failure_to_start(): void
    {
        $started = microtime(true);
        $supervisor = $this->supervise([Component::process(HangingStartProcess::class, startTimeout: 1)]);

        self::assertSame(1, $this->exitCode($supervisor, 20.0));
        self::assertLessThan(15.0, microtime(true) - $started, 'stopped when its time was up');
        self::assertStringContainsString('did not start: was not ready within 1s', $this->announced());
    }

    public function test_a_non_critical_component_that_cannot_start_is_left_down_while_the_rest_runs_on(): void
    {
        $supervisor = $this->supervise([
            Component::process(BrokenStartProcess::class, critical: false),
            Component::process(SignalProcess::class),
        ]);

        self::assertTrue($this->pollUntil(fn() => BrokenStartProcess::status()?->state === ProcessState::FAILED));
        self::assertNotNull(SignalProcess::status(), 'the rest runs on');
        self::assertTrue($this->isAlive($supervisor), 'and so does the application');
        self::assertFalse(BrokenStartProcess::stop(), 'a failed component has no process to signal');
        self::assertStringContainsString('BrokenStartProcess failed: did not start', $this->logged());

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    // ── Running ───────────────────────────────────────────────────────────────

    public function test_a_crash_after_it_was_ready_is_retried_with_back_off_up_to_its_limit(): void
    {
        $supervisor = $this->supervise([Component::process(CrashingProcess::class, retries: 2)]);

        self::assertSame(1, $this->exitCode($supervisor, 20.0), 'retries spent, the application stops');
        $runs = $this->runTimes('crashing');
        self::assertCount(3, $runs, 'the first run and two retries');
        self::assertGreaterThanOrEqual(1.0, $runs[1] - $runs[0] - 0.2, 'the first retry waits 1s');
        self::assertGreaterThanOrEqual(2.0, $runs[2] - $runs[1] - 0.2, 'the second waits 2s');
        self::assertStringContainsString('restart 1 of 2 in 1s', $this->logged());
        self::assertStringContainsString('restart 2 of 2 in 2s', $this->logged());
        self::assertStringContainsString('after 2 restarts within 30 minutes', $this->announced());
    }

    public function test_a_process_that_finished_its_work_is_not_restarted(): void
    {
        $supervisor = $this->supervise([Component::process(FinishingProcess::class)]);

        self::assertSame(0, $this->exitCode($supervisor), 'nothing is left to run: the application ends');
        self::assertCount(1, $this->runTimes('finishing'));
    }

    public function test_a_component_stopped_on_request_is_not_restarted(): void
    {
        $supervisor = $this->supervise([
            Component::process(SignalProcess::class),
            Component::process(FinishingProcess::class),
        ]);
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status() !== null));
        $pid = SignalProcess::status()->pid;

        posix_kill($pid, SIGTERM);

        self::assertTrue($this->pollUntil(fn() => !$this->isAlive($pid)));
        usleep(1_500_000);
        self::assertSame(ProcessState::TERMINATED, SignalProcess::status()?->state, 'recorded as stopped');
        self::assertFalse(SignalProcess::stop(), 'there is nothing to signal');
        self::assertTrue($this->isAlive($supervisor), 'and not a failure');
        self::assertStringContainsString('SignalProcess stopped.', $this->logged());
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_stopping_the_application_stops_every_component_cleanly(): void
    {
        $supervisor = $this->supervise([
            Component::process(SignalProcess::class),
            Component::daemon(LoopDaemon::class),
        ]);
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status() !== null && LoopDaemon::status() !== null));

        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertNull(SignalProcess::status());
        self::assertNull(LoopDaemon::status());
    }

    // ── Daemons ───────────────────────────────────────────────────────────────

    public function test_a_daemon_that_gives_up_stops_the_application(): void
    {
        $supervisor = $this->supervise([Component::daemon(CrashCapDaemon::class)]);

        self::assertSame(1, $this->exitCode($supervisor, 20.0));
        self::assertStringContainsString('CrashCapDaemon failed: gave up', $this->announced());
    }

    public function test_a_daemon_whose_workers_never_come_up_fails_its_start(): void
    {
        $supervisor = $this->supervise([Component::daemon(BrokenWorkersDaemon::class, startTimeout: 2)]);

        self::assertSame(1, $this->exitCode($supervisor, 30.0));
        self::assertStringContainsString(
            'BrokenWorkersDaemon failed: did not start: was not ready within 2s',
            $this->announced(),
        );
    }

    public function test_a_daemon_is_never_restarted(): void
    {
        $supervisor = $this->supervise([Component::daemon(LoopDaemon::class)]);
        self::assertTrue(
            $this->pollUntil(fn() => str_contains($this->logged(), 'LoopDaemon is up.')),
            "the daemon reports ready once its workers run:\n" . $this->logged(),
        );
        $status = LoopDaemon::status();

        posix_kill($status->pid, SIGKILL);
        foreach ($status->workers as $worker) {
            @posix_kill($worker->pid, SIGKILL);
        }

        self::assertSame(1, $this->exitCode($supervisor));
        self::assertStringContainsString('LoopDaemon failed: killed by signal 9', $this->announced());
    }

    // ── Outside programs ──────────────────────────────────────────────────────

    public function test_a_program_that_exits_zero_has_done_its_work(): void
    {
        $supervisor = $this->supervise([Component::command(['sh', '-c', 'exit 0'], name: 'job')]);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertStringContainsString('job finished its work.', $this->logged());
    }

    public function test_a_failing_program_is_not_retried_and_stops_the_application_when_critical(): void
    {
        $supervisor = $this->supervise([Component::command(['sh', '-c', 'exit 3'], name: 'job')]);

        self::assertSame(1, $this->exitCode($supervisor));
        self::assertStringContainsString('job failed: exit code 3. It is critical', $this->announced());
    }

    public function test_a_failing_program_that_is_not_critical_is_left_down(): void
    {
        $supervisor = $this->supervise([
            Component::command(['sh', '-c', 'exit 3'], name: 'job', critical: false),
            Component::process(SignalProcess::class),
        ]);

        self::assertTrue($this->waitLogged('job failed: exit code 3. It is not critical'));
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status() !== null), 'the rest runs on');
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_a_program_that_ignores_sigterm_is_killed_after_its_grace(): void
    {
        $supervisor = $this->supervise([
            Component::command(['sh', '-c', 'trap "" TERM; sleep 60'], name: 'stubborn', grace: 1.0),
        ]);
        self::assertTrue($this->waitLogged('stubborn is up.'));
        usleep(300_000);

        $started = microtime(true);
        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertLessThan(5.0, microtime(true) - $started, 'SIGKILL after one second of grace');
    }

    public function test_a_script_runs_in_the_project_root_with_the_application_environment(): void
    {
        $_ENV['WK_FROM_DOTENV'] = 'loaded';
        $script = $this->storage . '/probe.php';
        file_put_contents($script, '<?php file_put_contents(getenv("WK_MARKER"), '
            . 'getcwd() . "|" . getenv("WK_FROM_DOTENV") . "|" . implode(",", array_slice($argv, 1)));');
        $components = (new \Flytachi\Winter\Kernel\App\Components($this->storage))
            ->script('probe.php', args: ['a', 'b'])
            ->all();

        $supervisor = $this->supervise($components);

        self::assertSame(0, $this->exitCode($supervisor));
        unset($_ENV['WK_FROM_DOTENV']);
        self::assertSame(realpath($this->storage) . '|loaded|a,b', (string) file_get_contents($this->marker));
        self::assertStringContainsString('probe finished its work.', $this->logged());
    }

    public function test_a_program_that_cannot_be_found_fails(): void
    {
        $supervisor = $this->supervise([Component::command(['no-such-program-for-winter-tests'], name: 'missing')]);

        self::assertSame(1, $this->exitCode($supervisor));
        self::assertStringContainsString('missing failed: exit code 127', $this->announced());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return list<float> */
    private function runTimes(string $who): array
    {
        $lines = @file($this->marker, FILE_IGNORE_NEW_LINES) ?: [];
        $times = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, "{$who} run")) {
                $times[] = (float) (explode(' ', $line)[2] ?? 0);
            }
        }
        return $times;
    }
}
