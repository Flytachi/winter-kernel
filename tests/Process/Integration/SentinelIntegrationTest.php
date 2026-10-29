<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * An application killed outright — `kill -9`, the OOM killer — on a host, not in a
 * container. Its components run in process groups of their own and used to outlive it:
 * an HTTP server kept its port, and the application's lock, inherited by every
 * component, kept the next `call run` from starting ("already running" for a dead PID).
 * The sentinel stops them; the lock stays with the application alone.
 */
#[Group('integration')]
final class SentinelIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    private string $marker;
    /** Where the test's shutdown hook writes; null once the test is over. */
    private static ?string $hooksFile = null;
    /** A sleep nothing else on the machine runs, to find the outside program by. */
    private string $nap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = $this->storage . '/marker';
        putenv('WK_MARKER=' . $this->marker);
        (new \ReflectionMethod(SignalProcess::class, 'store'))->invoke(null);
        $this->nap = '3' . random_int(1000, 9999) . '.5';
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        exec('pkill -KILL -f ' . escapeshellarg('sleep ' . $this->nap) . ' 2>/dev/null');
        $this->actAsApplication(null);
        parent::tearDown();
    }

    public function test_the_components_of_an_application_killed_outright_are_stopped(): void
    {
        $supervisor = $this->supervise([
            Component::process(SignalProcess::class),
            Component::command(['sleep', $this->nap], 'nap', critical: false),
        ], asApplication: true);
        // Inside run(): its signal handlers are set by then.
        self::assertTrue($this->pollUntil(fn() => $this->started() && $this->napPid() !== null));
        $process = SignalProcess::status()->pid;
        $program = (int) $this->napPid();

        posix_kill($supervisor, SIGKILL);
        $this->waitExit($supervisor);

        self::assertTrue(
            $this->pollUntil(fn() => !$this->isAlive($process) && !$this->isAlive($program), 13.0),
            'the components outlived the application',
        );
        self::assertStringContainsString('terminate', (string) file_get_contents($this->marker), 'stopped gracefully, with SIGTERM');

        // The lock went with the application: it can be started again at once.
        $record = ApplicationRuntime::record(self::$testApp);
        self::assertNotNull($record);
        self::assertFalse(ApplicationRuntime::isAlive($record));
        ApplicationRuntime::claim(self::$testApp, []);
        ApplicationRuntime::release(self::$testApp);
    }

    public function test_after_a_normal_stop_the_sentinel_leaves_and_touches_nothing(): void
    {
        $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        self::assertTrue($this->pollUntil(fn() => $this->started() && $this->sentinels($supervisor) !== []));
        $sentinel = $this->sentinels($supervisor)[0];

        posix_kill($supervisor, SIGTERM);

        self::assertSame(0, $this->exitCode($supervisor));
        self::assertTrue($this->pollUntil(fn() => !$this->isAlive($sentinel)), 'the sentinel is still there');
        self::assertStringNotContainsString('is gone', $this->logged());
    }

    public function test_a_component_holds_no_part_of_the_application_lock(): void
    {
        $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        self::assertTrue($this->pollUntil(fn() => $this->started()));
        $process = SignalProcess::status()->pid;

        // Stopped without its components, and without the sentinel to stop them: what
        // keeps the lock now is only what inherited it.
        foreach ($this->sentinels($supervisor) as $sentinel) {
            posix_kill($sentinel, SIGKILL);
        }
        posix_kill($supervisor, SIGKILL);
        $this->waitExit($supervisor);

        self::assertTrue($this->isAlive($process), 'the component runs on');
        self::assertFalse(ApplicationRuntime::isAlive(ApplicationRuntime::record(self::$testApp)));
        posix_kill($process, SIGKILL);
    }

    /**
     * The sentinel is forked from the application: an exit() there ran every shutdown
     * function the application had registered — a log flushed twice, a resource closed
     * under the application.
     */
    public function test_the_sentinel_runs_none_of_the_applications_shutdown_hooks(): void
    {
        $hooks = $this->storage . '/hooks';
        self::$hooksFile = $hooks;
        register_shutdown_function(static function (): void {
            if (self::$hooksFile !== null) {
                @file_put_contents(self::$hooksFile, getmypid() . "\n", FILE_APPEND);
            }
        });
        try {
            $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
            self::assertTrue($this->pollUntil(fn() => $this->started() && $this->sentinels($supervisor) !== []));
            $sentinel = $this->sentinels($supervisor)[0];

            posix_kill($supervisor, SIGTERM);
            self::assertSame(0, $this->exitCode($supervisor));
            self::assertTrue($this->pollUntil(fn() => !$this->isAlive($sentinel)));

            $ran = array_map('intval', array_filter(explode("\n", (string) @file_get_contents($hooks))));
            self::assertNotContains($sentinel, $ran);
        } finally {
            self::$hooksFile = null;
        }
    }

    public function test_a_sentinel_that_dies_first_is_reported(): void
    {
        $supervisor = $this->supervise([Component::process(SignalProcess::class)], asApplication: true);
        self::assertTrue($this->pollUntil(fn() => $this->started() && $this->sentinels($supervisor) !== []));

        posix_kill($this->sentinels($supervisor)[0], SIGKILL);

        self::assertTrue($this->waitLogged('is gone: if the application dies now without stopping its components'));
        self::assertTrue($this->isAlive($supervisor), 'the application runs on');
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    private function started(): bool
    {
        return str_contains((string) @file_get_contents($this->marker), 'start');
    }

    private function napPid(): ?int
    {
        $out = trim((string) shell_exec('pgrep -f ' . escapeshellarg('sleep ' . $this->nap)));
        return $out === '' ? null : (int) strtok($out, "\n");
    }

    /**
     * The sentinel of this supervisor — never one left by another run on the machine.
     *
     * @return list<int>
     */
    private function sentinels(int $supervisor): array
    {
        $out = trim((string) shell_exec('pgrep -P ' . $supervisor . ' -f winter-sentinel'));
        return $out === '' ? [] : array_map('intval', explode("\n", $out));
    }
}
