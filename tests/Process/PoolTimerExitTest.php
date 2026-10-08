<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use PHPUnit\Framework\TestCase;

/**
 * A process body that used a connection pool must still end.
 *
 * Under Swoole the body runs inside `Coroutine\run()`, which returns only once nothing is
 * left on the reactor. A connection pool arms a repeating housekeeping timer on its first
 * borrow (on by default since winter-cpool 1.1.0), so a process or daemon worker that had
 * touched the database finished its body and then hung: the engine had already disarmed its
 * own grace timer on the way out, so nothing was left to end it but a `kill -9`. An HTTP
 * worker never showed it — `workerExit` shuts the pools down — but nothing did that for a
 * process.
 *
 * The child is a real, separate PHP process that boots the kernel the way an application
 * does, so the pools are wired by the same code: "did it terminate on its own" is the
 * thing under test, and that cannot be observed from inside the PHPUnit process.
 */
final class PoolTimerExitTest extends TestCase
{
    private const float CHILD_TIMEOUT = 10.0;

    private string $root;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the hang is a property of the Swoole scheduler.');
        }
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('the pool is exercised against SQLite.');
        }
        $this->root = sys_get_temp_dir() . '/wk_pool_exit_' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * Runs $body as its own PHP process with the kernel autoloaded and booted.
     *
     * @return array{code: int|null, output: string} `code` is null when the child had to
     *         be killed for outrunning the timeout — i.e. it never terminated on its own.
     */
    private function runChild(string $body): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $file = $this->root . '/child.php';
        file_put_contents($file, '<?php require ' . var_export($autoload, true) . ";\n"
            . 'const ROOT = ' . var_export($this->root, true) . ";\n"
            . <<<'PHP'
            use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
            use Flytachi\Winter\Kernel\Kernel;
            use Flytachi\Winter\Kernel\Process\Engine\SwooleEngine;
            use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

            final class ExitDb extends SqliteDbConfig
            {
                public function setUp(): void
                {
                    $this->path = ROOT . '/exit.sqlite';
                }
            }

            Kernel::init(pathRoot: ROOT);

            PHP
            . $body);

        $proc = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['XDEBUG_MODE' => 'off', 'LOG_LEVEL' => ''],
        );
        self::assertIsResource($proc, 'could not start the child process');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + self::CHILD_TIMEOUT;
        $code = null;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, SIGKILL);
                proc_close($proc);
                return ['code' => null, 'output' => $output];
            }
            usleep(20_000);
        }

        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($proc);

        return ['code' => $code, 'output' => $output];
    }

    public function test_a_body_that_queried_the_database_ends(): void
    {
        $result = $this->runChild(<<<'PHP'
            (new SwooleEngine(concurrency: 0, grace: 5.0))->enter(static function (): void {
                PpaConnectionPool::db(ExitDb::class)->query('SELECT 1');
            });
            echo "enter() returned\n";
            PHP);

        self::assertNotNull($result['code'], "the process never ended on its own:\n" . $result['output']);
        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('enter() returned', $result['output']);
    }

    public function test_a_body_whose_spawned_tasks_queried_the_database_ends(): void
    {
        $result = $this->runChild(<<<'PHP'
            $engine = new SwooleEngine(concurrency: 4, grace: 5.0);
            $engine->enter(static function () use ($engine): void {
                foreach (range(1, 4) as $n) {
                    $engine->spawn(static function (): void {
                        PpaConnectionPool::db(ExitDb::class)->query('SELECT 1');
                        \Swoole\Coroutine::sleep(0.05);
                    });
                }
            });
            echo "enter() returned\n";
            PHP);

        self::assertNotNull($result['code'], "the process never ended on its own:\n" . $result['output']);
        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('enter() returned', $result['output']);
    }

    public function test_tasks_still_running_keep_their_pool_until_they_finish(): void
    {
        // The body returns at once; the tasks borrow after it has. Shutting the pools
        // down the moment the body is done would pull the pool out from under them.
        $result = $this->runChild(<<<'PHP'
            $engine = new SwooleEngine(concurrency: 0, grace: 5.0);
            $engine->enter(static function () use ($engine): void {
                foreach (range(1, 3) as $n) {
                    $engine->spawn(static function () use ($n): void {
                        \Swoole\Coroutine::sleep(0.1 * $n);
                        $one = PpaConnectionPool::db(ExitDb::class)->query('SELECT 1')->fetchColumn();
                        echo "task {$n}: {$one}\n";
                    });
                }
            });
            echo "enter() returned\n";
            PHP);

        self::assertNotNull($result['code'], "the process never ended on its own:\n" . $result['output']);
        self::assertSame(0, $result['code'], $result['output']);
        foreach ([1, 2, 3] as $n) {
            self::assertStringContainsString("task {$n}: 1", $result['output']);
        }
    }
}
