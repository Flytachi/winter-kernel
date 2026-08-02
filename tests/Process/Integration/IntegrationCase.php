<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Core\KernelStore;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Base for live fork/signal integration tests. Each test boots an isolated
 * temp-storage kernel, forks a real Process/Daemon child, and observes it through
 * the shared runnable store and OS signals — exercising the true fork, coroutine
 * and signal machinery rather than mocks.
 */
abstract class IntegrationCase extends TestCase
{
    protected string $storage;
    /** @var list<int> Forked child PIDs to clean up. */
    private array $children = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix are required for the integration tests.');
        }

        $this->storage = sys_get_temp_dir() . '/wk_it_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->storage . '/runnable', 0777, true);

        Kernel::init(pathRoot: $this->storage, pathStorageRunnable: $this->storage . '/runnable');
        Container::init();

        // Kernel caches FileStorage by name against the path it was first built
        // with. Each test uses a fresh temp dir, so drop the cache or a reused
        // fixture class would read a previous test's (deleted) directory.
        foreach (['runnable', 'storages', 'volatiles'] as $cache) {
            (new \ReflectionProperty(KernelStore::class, $cache))->setValue(null, []);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            if ($this->isAlive($pid)) {
                @posix_kill($pid, SIGTERM);
            }
        }
        $deadline = microtime(true) + 4.0;
        foreach ($this->children as $pid) {
            while ($this->isAlive($pid) && microtime(true) < $deadline) {
                if (pcntl_waitpid($pid, $s, WNOHANG) === $pid) {
                    break;
                }
                usleep(50_000);
            }
            @posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $s, WNOHANG);
        }
        while (pcntl_waitpid(-1, $s, WNOHANG) > 0) {
            // drain any remaining direct children
        }
        $this->children = [];
        $this->rrmdir($this->storage);
    }

    /**
     * Runs $body in a forked child and returns its PID. The child resets inherited
     * signal handlers and, when $body returns, vanishes via SIGKILL so the test
     * runner's shutdown never runs (and never pollutes output) in the child.
     */
    protected function fork(callable $body): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            foreach ([SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2] as $signo) {
                pcntl_signal($signo, SIG_DFL);
            }
            try {
                $body();
            } catch (\Throwable) {
                // observed via the store / markers, not the child exit
            }
            posix_kill(getmypid(), SIGKILL);
        }
        $this->children[] = $pid;
        return $pid;
    }

    /**
     * Polls $cond until it is truthy or the timeout elapses.
     */
    protected function pollUntil(callable $cond, float $timeout = 8.0, float $step = 0.1): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            if ($cond()) {
                return true;
            }
            usleep((int) ($step * 1_000_000));
        } while (microtime(true) < $deadline);

        return (bool) $cond();
    }

    protected function isAlive(int $pid): bool
    {
        return $pid > 0 && posix_getpgid($pid) !== false;
    }

    /**
     * Waits for a forked child to exit (reaping it). Returns true once gone.
     */
    protected function waitExit(int $pid, float $timeout = 8.0): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            $r = pcntl_waitpid($pid, $s, WNOHANG);
            if ($r === $pid || $r === -1) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return false;
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
