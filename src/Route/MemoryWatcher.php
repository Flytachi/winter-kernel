<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Swoole-specific per-request memory reporter.
 *
 * Usage (standalone):
 *   $watcher = new MemoryWatcher();
 *   $watcher->attach($server);
 *   $server->on('request', $watcher->wrap($handler));
 *
 * Usage (with custom workerStart logic):
 *   $watcher->attach($server, function (Server $server, int $workerId): void {
 *       // your onWorkerStart code here
 *   });
 */
class MemoryWatcher
{
    private int $workerId = 0;
    private int $baseline = 0;

    /**
     * Registers the workerStart handler.
     *
     * @param Server        $server          Swoole HTTP server
     * @param callable|null $onWorkerStart   Optional extra callback — called after MemoryWatcher
     *                                       initialises, inside the same workerStart event.
     *                                       Signature: function(Server $server, int $workerId): void
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
