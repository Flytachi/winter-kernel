<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Swoole-specific per-request memory reporter.
 *
 * Usage:
 *   $watcher = new MemoryWatcher();
 *   $watcher->attach($server);                    // logs worker start
 *   $server->on('request', $watcher->wrap(
 *       function (Request $req, Response $res) use ($router) {
 *           $router->handle(new SwooleRequest($req), new SwooleResponse($res));
 *       }
 *   ));
 */
class MemoryWatcher
{
    private int $workerId = 0;
    private int $baseline = 0;

    public function attach(Server $server): void
    {
        $server->on('workerStart', function (Server $server, int $workerId): void {
            $this->workerId = $workerId;
            $this->baseline = memory_get_usage(false);
            echo sprintf(
                "[Worker %d] START | Baseline: %s\n",
                $this->workerId,
                $this->format($this->baseline)
            );
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
        if ($bytes >= 1024 * 1024) return round($bytes / 1024 / 1024, 2) . ' MB';
        if ($bytes >= 1024)        return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    private function formatDelta(int $bytes): string
    {
        $sign = $bytes >= 0 ? '+' : '-';
        $abs  = abs($bytes);
        if ($abs >= 1024 * 1024) return $sign . round($abs / 1024 / 1024, 2) . ' MB';
        if ($abs >= 1024)        return $sign . round($abs / 1024, 2) . ' KB';
        return $sign . $abs . ' B';
    }
}
