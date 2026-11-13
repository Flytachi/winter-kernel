<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\Probe;
use PHPUnit\Framework\Attributes\Group;

/**
 * Components start level by level — High, the application's onStarting(), Normal, Low —
 * and stop the other way. An outside program is ready when its readiness check says so,
 * stops with its own signal, and can be restarted.
 *
 * Before, every component started at once and got SIGTERM at once: a database started
 * together with the HTTP server that needs it, and was stopped while requests still used it.
 */
#[Group('integration')]
final class SupervisorPriorityIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    private const string PROGRAM = __DIR__ . '/../Fixtures/bin/program.php';

    private string $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = $this->storage . '/events';
    }

    // ── Starting ──────────────────────────────────────────────────────────────

    public function test_a_level_starts_once_the_one_before_it_is_ready(): void
    {
        $port = self::freePort();
        $flag = $this->storage . '/app-ready';
        $supervisor = $this->supervise([
            $this->program('web', priority: Priority::Low),
            $this->program('app', ['--delay=0.5', "--touch={$flag}"], ready: ['test', '-f', $flag]),
            $this->program('db', ['--delay=1', "--listen={$port}"], Priority::High, ready: "tcp://127.0.0.1:{$port}"),
        ]);

        self::assertTrue($this->pollUntil(fn() => $this->at('web', 'start') !== null), 'every level started');
        self::assertGreaterThanOrEqual($this->at('db', 'ready'), $this->at('app', 'start'), 'Normal waited for High');
        self::assertGreaterThanOrEqual($this->at('app', 'ready'), $this->at('web', 'start'), 'Low waited for Normal');
        self::assertStringContainsString('Application starting High: db', $this->logged());
        self::assertStringContainsString('Application starting Normal: app', $this->logged());
        self::assertStringContainsString('Application starting Low: web', $this->logged());

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_a_ready_command_is_asked_until_it_succeeds(): void
    {
        $flag = $this->storage . '/db-ready';
        $supervisor = $this->supervise([
            $this->program('db', ['--delay=1', "--touch={$flag}"], Priority::High, ready: ['test', '-f', $flag]),
            $this->program('app'),
        ]);

        self::assertTrue($this->pollUntil(fn() => $this->at('app', 'start') !== null));
        self::assertGreaterThanOrEqual($this->at('db', 'ready'), $this->at('app', 'start'));

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_a_critical_program_never_ready_stops_the_application(): void
    {
        $supervisor = $this->supervise([
            $this->program('db', priority: Priority::High, ready: ['false'], startTimeout: 1),
            $this->program('app'),
        ]);

        self::assertSame(1, $this->exitCode($supervisor));
        self::assertStringContainsString('db failed: did not start: was not ready within 1s', $this->announced());
        self::assertNull($this->at('app', 'start'), 'nothing above it started');
    }

    public function test_a_level_does_not_wait_for_a_non_critical_one_that_never_gets_ready(): void
    {
        $supervisor = $this->supervise([
            $this->program('cache', priority: Priority::High, ready: ['false'], startTimeout: 1, critical: false),
            $this->program('app'),
        ]);

        self::assertTrue($this->pollUntil(fn() => $this->at('app', 'start') !== null), 'the rest started');
        self::assertStringContainsString('cache failed: did not start: was not ready within 1s', $this->logged());

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_without_priorities_everything_starts_at_once_and_nothing_more_is_said(): void
    {
        $supervisor = $this->supervise([$this->program('a'), $this->program('b')]);

        self::assertTrue($this->pollUntil(fn() => $this->at('a', 'start') !== null && $this->at('b', 'start') !== null));
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
        self::assertStringNotContainsString('Application starting', $this->logged());
    }

    // ── onStarting ────────────────────────────────────────────────────────────

    public function test_on_starting_runs_between_high_and_the_rest_in_a_process_of_its_own(): void
    {
        $port = self::freePort();
        $events = $this->events;
        $supervisor = $this->supervise(
            [
                $this->program('db', ['--delay=1', "--listen={$port}"], Priority::High, ready: "tcp://127.0.0.1:{$port}"),
                $this->program('app'),
            ],
            starting: static function () use ($events): void {
                file_put_contents($events, sprintf("starting %d %.6f\n", getmypid(), microtime(true)), FILE_APPEND | LOCK_EX);
            },
        );

        self::assertTrue($this->pollUntil(fn() => $this->at('app', 'start') !== null));
        $starting = $this->line('starting');
        self::assertNotNull($starting);
        self::assertGreaterThanOrEqual($this->at('db', 'ready'), (float) $starting[2], 'after High was ready');
        self::assertGreaterThanOrEqual((float) $starting[2], $this->at('app', 'start'), 'before Normal');
        self::assertNotSame((string) $supervisor, $starting[1], 'not in the application process');
        self::assertStringContainsString('onStarting done in', $this->logged());

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_on_starting_that_throws_stops_the_application_before_the_rest(): void
    {
        // Ready means its signal handlers are set: the stop that follows is one it answers.
        $flag = $this->storage . '/db-ready';
        $supervisor = $this->supervise(
            [
                $this->program('db', ["--touch={$flag}"], Priority::High, ready: ['test', '-f', $flag]),
                $this->program('app'),
            ],
            starting: static function (): void {
                throw new \RuntimeException('migration 42 failed');
            },
        );

        self::assertSame(1, $this->exitCode($supervisor));
        self::assertStringContainsString('onStarting failed — RuntimeException: migration 42 failed', $this->reports());
        self::assertStringContainsString('onStarting failed (exit code 1): the application stops.', $this->announced());
        self::assertNull($this->at('app', 'start'));
        self::assertNotNull($this->at('db', 'stopped'), 'what started is stopped');
    }

    public function test_a_stop_during_on_starting_ends_it_and_starts_nothing_more(): void
    {
        $supervisor = $this->supervise(
            [$this->program('app')],
            starting: static function (): void {
                sleep(30);
            },
        );
        usleep(800_000);

        $sent = microtime(true);
        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertLessThan(3.0, microtime(true) - $sent);
        self::assertNull($this->at('app', 'start'));
    }

    // ── Stopping ──────────────────────────────────────────────────────────────

    public function test_a_stop_goes_low_normal_high_each_once_the_one_before_is_down(): void
    {
        $supervisor = $this->supervise([
            $this->program('db', ['--stop-delay=0.3'], Priority::High),
            $this->program('app', ['--stop-delay=0.5']),
            $this->program('web', ['--stop-delay=0.5'], Priority::Low),
        ]);
        self::assertTrue($this->pollUntil(fn() => $this->at('web', 'start') !== null));

        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertGreaterThanOrEqual($this->at('web', 'stopped'), $this->at('app', 'stop-TERM'), 'Normal after Low was down');
        self::assertGreaterThanOrEqual($this->at('app', 'stopped'), $this->at('db', 'stop-TERM'), 'High after Normal was down');
        self::assertStringContainsString('Application stopping Normal: app', $this->logged());
        self::assertStringContainsString('Application stopping High: db', $this->logged());
    }

    public function test_a_program_is_stopped_with_its_own_signal(): void
    {
        $supervisor = $this->supervise([$this->program('db', stopSignal: SIGINT)]);
        self::assertTrue($this->pollUntil(fn() => $this->at('db', 'start') !== null));

        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertNotNull($this->at('db', 'stop-INT'));
        self::assertNull($this->at('db', 'stop-TERM'));
    }

    // ── Retries ───────────────────────────────────────────────────────────────

    public function test_a_program_with_retries_is_restarted_after_a_crash(): void
    {
        $supervisor = $this->supervise([
            $this->program('worker', ['--after=0.3', '--exit=3'], retries: 1, critical: false),
        ]);

        self::assertTrue($this->waitLogged('worker crashed: exit code 3; restart 1 of 1 in 1s.'));
        self::assertTrue($this->waitLogged('worker failed: exit code 3, after 1 restarts'));
        self::assertSame(2, substr_count((string) @file_get_contents($this->events), 'worker start'));

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    // ── Alive ─────────────────────────────────────────────────────────────────

    /** It runs, but no longer answers: stopped with its own signal, and restarted as crashed. */
    public function test_a_program_that_stops_answering_is_restarted_as_crashed(): void
    {
        $port = self::freePort();
        $supervisor = $this->supervise([
            $this->program(
                'db',
                ["--listen={$port}", '--close-after=1'],
                ready: "tcp://127.0.0.1:{$port}",
                stopSignal: SIGINT,
                retries: 1,
                critical: false,
                alive: Probe::tcp("127.0.0.1:{$port}", every: 0.3, timeout: 0.5, failures: 2),
            ),
        ]);

        self::assertTrue($this->waitLogged('db alive check failed (1/2): exit code 1.'));
        self::assertTrue($this->waitLogged('db is not answering (2 checks in a row): stopping it with SIGINT.'));
        self::assertTrue($this->waitLogged('db crashed: was not answering; restart 1 of 1 in 1s.'));
        self::assertTrue($this->waitLogged('db failed: was not answering, after 1 restarts'), 'it hung again');
        self::assertSame(2, substr_count((string) @file_get_contents($this->events), 'db stop-INT'));

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    /** Its stop signal, SIGTERM halfway through its grace, SIGKILL at the end of it. */
    public function test_a_hung_program_that_ignores_its_signals_is_stopped_step_by_step(): void
    {
        $port = self::freePort();
        $supervisor = $this->supervise([
            $this->program(
                'db',
                ["--listen={$port}", '--close-after=0.5', '--deaf'],
                ready: "tcp://127.0.0.1:{$port}",
                stopSignal: SIGINT,
                critical: false,
                alive: Probe::tcp("127.0.0.1:{$port}", every: 0.3, timeout: 0.5, failures: 1),
                grace: 2.0,
            ),
        ]);

        self::assertTrue($this->waitLogged('db is not answering (1 checks in a row): stopping it with SIGINT.'));
        self::assertTrue($this->waitLogged('db still running: SIGTERM.'));
        self::assertTrue($this->waitLogged('db still running: SIGKILL.'));
        self::assertTrue($this->waitLogged('db failed: was not answering.'));
        $int = $this->at('db', 'stop-INT');
        $term = $this->at('db', 'stop-TERM');
        self::assertNotNull($int);
        self::assertNotNull($term);
        self::assertGreaterThanOrEqual(0.8, $term - $int, 'SIGTERM came about halfway through the 2 s grace');

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    /** Failures count only in a row: one pass starts the count over. */
    public function test_one_passing_check_resets_the_count(): void
    {
        $flag = $this->storage . '/alive';
        $supervisor = $this->supervise([
            $this->program(
                'db',
                ["--touch={$flag}"],
                ready: ['test', '-f', $flag],
                alive: Probe::command(['test', '-f', $flag], every: 0.3, timeout: 1.0, failures: 3),
            ),
        ]);
        self::assertTrue($this->waitLogged('db is up.'));

        unlink($flag);
        self::assertTrue($this->waitLogged('db alive check failed (1/3)'));
        touch($flag);
        self::assertTrue($this->waitLogged('db answers its alive check again.'));
        usleep(1_200_000);
        self::assertStringNotContainsString('not answering', $this->logged());
        self::assertNull($this->at('db', 'stop-TERM'), 'still running, never stopped');

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param list<string> $options
     * @param list<string>|string|null $ready
     */
    private function program(
        string $name,
        array $options = [],
        Priority $priority = Priority::Normal,
        array|string|null $ready = null,
        int $stopSignal = SIGTERM,
        int $retries = 0,
        int $startTimeout = 10,
        bool $critical = true,
        ?Probe $alive = null,
        float $grace = 5.0,
    ): Component {
        return Component::command(
            [PHP_BINARY, self::PROGRAM, "--name={$name}", "--log={$this->events}", ...$options],
            $name,
            critical: $critical,
            grace: $grace,
            alive: $alive,
            priority: $priority,
            ready: $ready,
            stopSignal: $stopSignal,
            retries: $retries,
            startTimeout: $startTimeout,
        );
    }

    /** When a program said an event first; null when it did not. */
    private function at(string $name, string $event): ?float
    {
        foreach (file($this->events, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$who, $what, $time] = explode(' ', $line) + ['', '', ''];
            if ($who === $name && $what === $event) {
                return (float) $time;
            }
        }
        return null;
    }

    /** @return list<string>|null The first line starting with $word, split. */
    private function line(string $word): ?array
    {
        foreach (file($this->events, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, $word . ' ')) {
                return explode(' ', $line);
            }
        }
        return null;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }
}
