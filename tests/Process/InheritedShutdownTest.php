<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use PHPUnit\Framework\TestCase;

/**
 * A forked child inherits every shutdown callback its parent registered before the fork,
 * and PHP offers no way to unregister one. The process layer registers two such
 * backstops — `Process::prepareWorker()` for `onShutdown()`, and `Daemon::supervise()`
 * for the daemon's status record — so both must check that they are running in the
 * process that registered them.
 *
 * This exercises the first one end to end. Without Swoole, `spawn()` runs each task in a
 * forked child that exits when the task is done; unguarded, every one of those exits
 * would run the process's `onShutdown()` again, in the wrong process.
 *
 * The child runs with `php -n` on purpose: the guard only matters on the fork-based
 * engine, and skipping php.ini is what keeps Swoole out of it.
 */
final class InheritedShutdownTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('the fork engine needs pcntl and posix.');
        }
    }

    public function test_on_shutdown_runs_once_in_the_process_that_registered_it(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/wk_shutdown_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($tmp . '/runnable', 0777, true);
        $marker = $tmp . '/shutdown.log';

        $script = $tmp . '/child.php';
        file_put_contents($script, <<<PHP
        <?php
        require {$this->quote($root . '/vendor/autoload.php')};

        Flytachi\\Winter\\Kernel\\Kernel::init(
            pathRoot: {$this->quote($tmp)},
            pathStorageRunnable: {$this->quote($tmp . '/runnable')},
        );
        Flytachi\\Winter\\DI\\Container::init();

        Flytachi\\Winter\\Kernel\\Tests\\Process\\Fixtures\\SpawnShutdownProcess::start();
        PHP);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-n', $script],
            $descriptors,
            $pipes,
            null,
            ['WK_MARKER' => $marker, 'XDEBUG_MODE' => 'off'],
        );
        self::assertIsResource($proc, 'could not start the child process');

        $output = '';
        $deadline = microtime(true) + 15.0;
        while (proc_get_status($proc)['running'] && microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            usleep(50_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_terminate($proc, SIGKILL);
        proc_close($proc);

        self::assertFileExists($marker, "the process never reached onShutdown(). Output:\n" . $output);

        $pids = array_values(array_filter(
            array_map('trim', file($marker, FILE_IGNORE_NEW_LINES) ?: []),
            static fn(string $line): bool => $line !== '',
        ));

        $this->rrmdir($tmp);

        self::assertCount(
            1,
            $pids,
            'onShutdown() ran in a forked task child: ' . implode(', ', $pids),
        );
    }

    /** Renders $value as a PHP literal for the generated child script. */
    private function quote(string $value): string
    {
        return var_export($value, true);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
