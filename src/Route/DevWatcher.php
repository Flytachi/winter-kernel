<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;

/**
 * Development-only Swoole watcher: per-request memory reporting + code hot-reload.
 *
 * Attached only by `call run dev`. Two jobs:
 *   1. Memory — logs each worker's baseline at workerStart and per-request growth.
 *   2. Reload — polls the watched source tree; on any `.php` change it restarts the
 *      whole `call run dev` process (a full, fresh boot), so every change is picked up.
 *
 * The full restart (not `$server->reload()`) is deliberate: `boot()` and the router
 * scan run in the master before workers fork, so a plain worker reload would keep the
 * old controller/service classes cached in the master. Re-exec'ing the process image
 * is the only reliable way to reflect changes to master-loaded code.
 *
 * Usage (from Application::serveHttp, dev path):
 *   $dev = new DevWatcher([Kernel::$pathRoot]);
 *   $dev->attach($server, $onWorkerStart);
 *   $server->on('request', $dev->wrap($handler));
 *   $server->start();
 *   if ($dev->reloadRequested()) { $dev->reexec(); }
 */
final class DevWatcher
{
    private int $workerId = 0;
    private int $baseline = 0;

    /** @var array<string, int> Watched file path => last mtime. */
    private array $snapshot = [];
    private bool $reloadRequested = false;
    private ?int $timerId = null;

    /**
     * @param list<string> $watchPaths Directories scanned for `.php` changes.
     * @param float $interval Poll interval in seconds.
     * @param list<string> $exclude Directory names skipped during the scan.
     */
    public function __construct(
        private readonly array $watchPaths,
        private readonly float $interval = 1.0,
        private readonly array $exclude = ['vendor', 'storage', '.git', 'node_modules'],
    ) {
    }

    /**
     * Registers the workerStart memory baseline (composing an optional extra
     * callback) and the master-side file-watch timer.
     *
     * @param callable|null $onWorkerStart function(Server $server, int $workerId): void
     */
    public function attach(Server $server, ?callable $onWorkerStart = null): void
    {
        $server->on('workerStart', function (Server $server, int $workerId) use ($onWorkerStart): void {
            $this->workerId = $workerId;
            $this->baseline = memory_get_usage(false);
            echo sprintf(
                "[Worker %d] START | Baseline: %s\n",
                $this->workerId,
                $this->format($this->baseline)
            );
            if ($onWorkerStart !== null) {
                $onWorkerStart($server, $workerId);
            }
        });

        // The file watcher lives in the master reactor. On a change it stops the
        // server; serve() then re-exec's the process (see reexec()).
        $server->on('start', function (Server $server): void {
            $this->snapshot = $this->scan();
            $this->timerId = Timer::tick((int) ($this->interval * 1000), function () use ($server): void {
                $current = $this->scan();
                if ($current === $this->snapshot) {
                    return;
                }
                $changed = $this->firstChange($this->snapshot, $current);
                echo sprintf(
                    "\n[dev] change detected%s — restarting server...\n",
                    $changed !== null ? " ({$changed})" : ''
                );
                $this->reloadRequested = true;
                if ($this->timerId !== null) {
                    Timer::clear($this->timerId);
                    $this->timerId = null;
                }
                $server->shutdown();
            });
        });
    }

    public function wrap(callable $handler): callable
    {
        return function (Request $request, Response $response) use ($handler): void {
            $before = memory_get_usage(false);

            $handler($request, $response);

            $after = memory_get_usage(false);

            echo sprintf(
                "[Worker %d] REQUEST => (before: %s, after: %s, delta: %s, growth: %s, peak: %s)\n",
                $this->workerId,
                $this->format($before),
                $this->format($after),
                $this->formatDelta($after - $before),
                $this->formatDelta($after - $this->baseline),
                $this->format(memory_get_peak_usage(false))
            );
        };
    }

    /** True when a watched file changed and the server was stopped for a restart. */
    public function reloadRequested(): bool
    {
        return $this->reloadRequested;
    }

    /**
     * Replaces the current process image with a fresh `php <call> run dev`.
     * Call only after `$server->start()` has returned (workers already stopped).
     */
    public function reexec(): never
    {
        $argv = $_SERVER['argv'] ?? [];
        if (function_exists('pcntl_exec') && $argv !== []) {
            pcntl_exec(PHP_BINARY, $argv);
        }
        // pcntl_exec only returns on failure (or is unavailable): fall back to a
        // non-zero exit so an external supervisor can restart the process.
        echo "[dev] cannot re-exec (ext-pcntl unavailable) — exiting for a supervisor restart.\n";
        exit(1);
    }

    /** @return array<string, int> path => mtime for every watched `.php` file. */
    private function scan(): array
    {
        $files = [];
        foreach ($this->watchPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    function (\SplFileInfo $file): bool {
                        if ($file->isDir()) {
                            return !in_array($file->getFilename(), $this->exclude, true);
                        }
                        return $file->getExtension() === 'php';
                    }
                )
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $files[$file->getPathname()] = (int) $file->getMTime();
            }
        }
        return $files;
    }

    /** Name of the first added/changed/removed file, for the console notice. */
    private function firstChange(array $old, array $new): ?string
    {
        foreach ($new as $path => $mtime) {
            if (!isset($old[$path]) || $old[$path] !== $mtime) {
                return basename($path);
            }
        }
        foreach ($old as $path => $mtime) {
            if (!isset($new[$path])) {
                return basename($path) . ' (removed)';
            }
        }
        return null;
    }

    private function format(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    private function formatDelta(int $bytes): string
    {
        $sign = $bytes >= 0 ? '+' : '-';
        $abs  = abs($bytes);
        if ($abs >= 1024 * 1024) {
            return $sign . round($abs / 1024 / 1024, 2) . ' MB';
        }
        if ($abs >= 1024) {
            return $sign . round($abs / 1024, 2) . ' KB';
        }
        return $sign . $abs . ' B';
    }
}
