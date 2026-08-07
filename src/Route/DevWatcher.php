<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route;

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
 * Usage (from WinterApplication::serveHttp, dev path):
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
    private bool $stopping = false;
    private readonly bool $color;

    /**
     * File the watcher touches to ask for a restart, because a property cannot carry that
     * answer to the process that acts on it.
     *
     * The watch timer is armed from `onStart`, and as soon as the application declares a
     * companion — `#[EnableProcess]`, `#[EnableDaemon]`, `#[EnableScheduler]` — Swoole runs
     * that callback in a **manager process of its own**. Measured: with no companion the
     * flag set in the timer is visible after `start()` returns; with one, `onStart` runs in
     * pid B while `start()` was called in pid A, so pid A saw `false`, skipped the re-exec
     * and exited 0. The symptom was exact — "↻ change … restarting…" printed, then the
     * server simply gone — and it only ever appeared in applications with a companion,
     * which is why it survived so long.
     *
     * The path carries the watcher's own pid, so two dev servers cannot collide and a
     * marker abandoned by an earlier run cannot be mistaken for this one's.
     */
    private readonly string $signalPath;

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
        $this->color = self::wantsColor();
        // Constructed in the process that calls start(); every child inherits the value,
        // so both sides of the fork name the same file without having to agree on one.
        $this->signalPath = sys_get_temp_dir() . '/winter-dev-reload-'
            . substr(sha1(implode('|', $watchPaths)), 0, 12) . '-' . getmypid();
        @unlink($this->signalPath);
    }

    /** Colour the dev output using the same LOG_COLOR contract as the logger. */
    private static function wantsColor(): bool
    {
        return match (strtolower((string) (env('LOG_COLOR', 'auto')))) {
            'always' => true,
            'never'  => false,
            default  => defined('STDOUT') && stream_isatty(STDOUT),
        };
    }

    /** Wraps text in an ANSI colour when colour is on; plain text otherwise. */
    private function paint(string $code, string $text): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
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
            echo $this->paint('32', '●') . ' ' . $this->paint('36', '[dev]')
                . ' worker ' . $this->workerId
                . $this->paint('90', ' · baseline ') . $this->format($this->baseline) . "\n";
            if ($onWorkerStart !== null) {
                $onWorkerStart($server, $workerId);
            }
        });

        // The file watcher lives in the master reactor. On a change it stops the
        // server; serve() then re-exec's the process (see reexec()).
        $server->on('start', function (Server $server): void {
            $this->snapshot = $this->scan();
            $this->timerId = Timer::tick((int) ($this->interval * 1000), function () use ($server): void {
                if ($this->stopping) {
                    return;
                }
                $current = $this->scan();
                if ($current === $this->snapshot) {
                    return;
                }

                // Validate changed files before restarting: a mid-edit syntax error
                // would kill the re-exec'd boot with no recovery. Keep the current
                // (working) server up and report the error; the fix triggers the reload.
                $invalid = $this->firstSyntaxError($this->changedPhpFiles($this->snapshot, $current));
                if ($invalid !== null) {
                    [$file, $error] = $invalid;
                    echo "\n" . $this->paint('31', '✗ [dev]') . ' syntax error in '
                        . $this->paint('1;31', basename($file))
                        . $this->paint('90', ' — keeping current server') . "\n"
                        . '    ' . $this->paint('90', $this->errorLine($error)) . "\n";
                    // Acknowledge this change so the same break isn't re-linted every
                    // tick; the next edit (the fix) is a fresh change → re-checked.
                    $this->snapshot = $current;
                    return;
                }

                $changed = $this->firstChange($this->snapshot, $current);
                echo "\n" . $this->paint('33', '↻ [dev]') . ' change'
                    . ($changed !== null ? $this->paint('90', ' · ') . $this->paint('1;33', $changed) : '')
                    . $this->paint('90', ' — restarting…') . "\n";
                $this->requestReload();
                if ($this->timerId !== null) {
                    Timer::clear($this->timerId);
                    $this->timerId = null;
                }
                $server->shutdown();
            });
        });

        // Stop the watch cleanly on shutdown: flag the stop and clear the poll
        // timer so no filesystem scan runs during reactor teardown. Otherwise the
        // (hooked) scan is left as a sleeping coroutine and Swoole force-kills the
        // worker after its exit timeout ("all coroutines are asleep - deadlock").
        $server->on('beforeShutdown', function (Server $server): void {
            $this->stopping = true;
            if ($this->timerId !== null) {
                Timer::clear($this->timerId);
                $this->timerId = null;
            }
        });
    }

    public function wrap(callable $handler): callable
    {
        return function (Request $request, Response $response) use ($handler): void {
            $before = memory_get_usage(false);

            $handler($request, $response);

            $after = memory_get_usage(false);

            echo $this->paint('36', '[dev]') . ' worker ' . $this->workerId
                . $this->paint('90', ' · before ') . $this->format($before)
                . $this->paint('90', ' · after ') . $this->format($after)
                . $this->paint('90', ' · Δ ') . $this->formatDelta($after - $before)
                . $this->paint('90', ' · growth ') . $this->formatDelta($after - $this->baseline)
                . $this->paint('90', ' · peak ') . $this->format(memory_get_peak_usage(false)) . "\n";
        };
    }

    /**
     * Records the request both in memory and on disk — the caller may be a different
     * process than the one that will act on it. See {@see $signalPath}.
     */
    private function requestReload(): void
    {
        $this->reloadRequested = true;
        @file_put_contents($this->signalPath, (string) getmypid());
    }

    /**
     * True when a watched file changed and the server was stopped for a restart.
     *
     * Read once: the marker is removed as it is answered, so a restart cannot repeat
     * itself if the process is stopped and started again.
     */
    public function reloadRequested(): bool
    {
        $requested = $this->reloadRequested || is_file($this->signalPath);

        // Both sides are cleared together, so the answer cannot differ depending on which
        // of them happened to carry it.
        $this->reloadRequested = false;
        @unlink($this->signalPath);

        return $requested;
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

    /**
     * Added or modified files still on disk (removals can't be linted and are a
     * valid reason to reload).
     *
     * @return list<string>
     */
    private function changedPhpFiles(array $old, array $new): array
    {
        $files = [];
        foreach ($new as $path => $mtime) {
            if ((!isset($old[$path]) || $old[$path] !== $mtime) && is_file($path)) {
                $files[] = $path;
            }
        }
        return $files;
    }

    /**
     * `php -l` each file; returns [path, output] of the first that fails to parse,
     * or null when all are valid (or validation is unavailable, so the reload just
     * proceeds as before).
     *
     * @param list<string> $files
     * @return array{0: string, 1: string}|null
     */
    private function firstSyntaxError(array $files): ?array
    {
        if (!function_exists('exec')) {
            return null;
        }
        foreach ($files as $file) {
            $out  = [];
            $code = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                return [$file, implode("\n", $out)];
            }
        }
        return null;
    }

    /** The "Parse error: ..." line from `php -l` output, for a compact notice. */
    private function errorLine(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (stripos($line, 'error') !== false) {
                return trim($line);
            }
        }
        return trim(strtok($output, "\n") ?: $output);
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
