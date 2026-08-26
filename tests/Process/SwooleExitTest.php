<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Tests\Process\Fixtures\BlankDaemon;
use PHPUnit\Framework\TestCase;

/**
 * `exit()` does not exit inside a process Swoole created.
 *
 * Not only inside a coroutine: a `Swoole\Process`, a user process added to a server with
 * `addProcess()`, and anything forked from one all inherit the same handicap. Swoole
 * raises {@see \Swoole\ExitException} instead of ending the process, execution continues
 * past the call, and an uncaught one yields a fatal with status 255 rather than the status
 * that was asked for.
 *
 * That is where a daemon master runs when the application serves HTTP and supervises a
 * daemon in the same command, so it is where its workers are forked. Three places assumed
 * `exit()` meant what it said:
 *
 * - a forked worker whose clean `exit(0)` was caught by the "the worker failed" handler
 *   and rewritten as `exit(1)`, which then escaped into the master's inherited stack and
 *   was logged as a supervisor crash;
 * - the same worker's exit status, which the supervisor reads to tell a finished worker
 *   from a crashed one;
 * - the Swoole grace backstop, a timer callback whose `exit(1)` never fired, leaving a
 *   body that ignores a stop request running forever.
 *
 * Every child here is a real, separate PHP process: an exit status is the thing under
 * test, so it cannot be observed from inside a PHPUnit process.
 */
final class SwooleExitTest extends TestCase
{
    private const float CHILD_TIMEOUT = 15.0;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('these guards are about Swoole exit semantics.');
        }
    }

    /**
     * Runs $body as its own PHP process with the kernel autoloaded.
     *
     * @return array{code: int|null, output: string} `code` is null when the child had to
     *         be killed for outrunning the timeout — i.e. it never terminated on its own.
     */
    private static function runChild(string $body, float $timeout = self::CHILD_TIMEOUT): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $file = tempnam(sys_get_temp_dir(), 'wk_exit_');
        file_put_contents($file, "<?php require " . var_export($autoload, true) . ";\n" . $body);

        $proc = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off'],
        );
        self::assertIsResource($proc, 'could not start the child process');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeout;
        $code = null;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // A process killed by a signal reports exitcode -1; the shell convention
                // of 128 + signal is what the rest of this test talks in.
                $code = ($status['signaled'] ?? false)
                    ? 128 + (int) $status['termsig']
                    : $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, SIGKILL);
                proc_close($proc);
                @unlink($file);
                return ['code' => null, 'output' => $output];
            }
            usleep(20_000);
        }

        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($proc);
        @unlink($file);

        return ['code' => $code, 'output' => $output];
    }

    /** Invokes one of the trait's private statics through a class that uses it. */
    private static function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(BlankDaemon::class, $method))->invoke(null, ...$args);
    }

    // ── exitStatusOf(): a Swoole exit is a decision, not a crash ─────────────

    public function test_an_ordinary_failure_maps_to_status_one(): void
    {
        self::assertSame(1, self::call('exitStatusOf', new \RuntimeException('boom')));
    }

    public function test_a_swallowed_exit_keeps_the_status_it_asked_for(): void
    {
        $captured = [];
        \Swoole\Coroutine\run(static function () use (&$captured): void {
            foreach ([0, 7] as $status) {
                try {
                    exit($status);
                } catch (\Swoole\ExitException $e) {
                    $captured[$status] = $e;
                }
            }
        });

        self::assertArrayHasKey(0, $captured, 'exit() under Swoole should raise ExitException');
        self::assertSame(0, self::call('exitStatusOf', $captured[0]), 'a clean exit must not read as a crash');
        self::assertSame(7, self::call('exitStatusOf', $captured[7]));
    }

    // ── Termination::leave(): the status arrives whatever the context ────────

    public function test_leave_exits_with_the_given_status(): void
    {
        $child = self::runChild(<<<'PHP'
        Flytachi\Winter\Kernel\Process\Internal\Termination::leave(3);
        echo 'ESCAPED';
        PHP);

        self::assertSame(3, $child['code']);
        self::assertStringNotContainsString('ESCAPED', $child['output']);
    }

    public function test_leave_delivers_the_status_from_inside_a_coroutine(): void
    {
        $child = self::runChild(<<<'PHP'
        Swoole\Coroutine\run(static function (): void {
            Flytachi\Winter\Kernel\Process\Internal\Termination::leave(4);
            echo 'ESCAPED';
        });
        echo 'ESCAPED';
        PHP);

        self::assertNotNull($child['code'], 'the child must not outlive leave()');
        self::assertSame(4, $child['code'], 'the status must survive a coroutine context');
        self::assertStringNotContainsString('ESCAPED', $child['output']);
        self::assertStringNotContainsString('ExitException', $child['output']);
    }

    /**
     * The real shape of the reported bug: an HTTP server with a companion user process,
     * which forks workers exactly as a daemon master does. `exit()` is neutralised there
     * even though no coroutine is involved.
     */
    public function test_a_worker_forked_from_a_swoole_user_process_still_reports_its_status(): void
    {
        $child = self::runChild(<<<'PHP'
        $server = new Swoole\Http\Server('127.0.0.1', 39531);
        $server->set(['worker_num' => 1, 'log_level' => 5]);
        $server->on('request', static fn($rq, $rs) => $rs->end('ok'));

        $server->addProcess(new Swoole\Process(static function (): void {
            Swoole\Runtime::enableCoroutine(0);

            $leave = new ReflectionMethod(
                Flytachi\Winter\Kernel\Tests\Process\Fixtures\BlankDaemon::class,
                'leaveChild',
            );

            $seen = [];
            foreach ([0, 2] as $want) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    $leave->invoke(null, $want);
                    echo 'ESCAPED';
                    sleep(5);
                }
                pcntl_waitpid($pid, $st);
                $seen[] = $want . ':' . (pcntl_wifexited($st)
                    ? 'exited=' . pcntl_wexitstatus($st)
                    : 'signal=' . pcntl_wtermsig($st));
            }
            echo 'REAPED ' . implode(' ', $seen) . "\n";

            Swoole\Process::daemon(false, false);
            sleep(30);
        }));

        $server->on('workerStart', static fn($s, $id) => Swoole\Timer::after(2000, static fn() => $s->shutdown()));
        $server->start();
        PHP);

        self::assertStringContainsString(
            'REAPED 0:exited=0 2:exited=2',
            $child['output'],
            "a forked worker must report its own status. Output:\n" . $child['output'],
        );
        self::assertStringNotContainsString('ESCAPED', $child['output']);
        self::assertStringNotContainsString('swoole exit', $child['output']);
    }

    // ── the Swoole grace backstop actually forces a stuck body down ──────────

    public function test_the_grace_backstop_forces_down_a_body_that_ignores_the_stop(): void
    {
        $child = self::runChild(<<<'PHP'
        $engine = new Flytachi\Winter\Kernel\Process\Engine\SwooleEngine(0, 0.3);
        $engine->enter(static function () use ($engine): void {
            // Ask for a stop without interrupting, then ignore it forever. Only the
            // grace backstop can end this process.
            Swoole\Timer::after(50, static fn() => $engine->requestStop(false));
            while (true) {
                Swoole\Coroutine::sleep(0.05);
            }
        });
        echo 'ESCAPED';
        PHP, timeout: 8.0);

        self::assertNotNull(
            $child['code'],
            'the grace backstop never fired: the body outlived its window',
        );
        self::assertSame(1, $child['code'], 'the backstop should force the process down with status 1');
        self::assertStringNotContainsString('ESCAPED', $child['output']);
    }
}
