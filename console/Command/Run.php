<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Base\RuntimeMode;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\BaseBoot;
use Flytachi\Winter\K2\Http\Adapter\SwooleRequest;
use Flytachi\Winter\K2\Http\Adapter\SwooleResponse;
use Flytachi\Winter\K2\Route\MemoryWatcher;
use Flytachi\Winter\K2\Route\Router;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\Logger\Context\CoroutineContext;
use Flytachi\Winter\Logger\LoggerFactory;

class Run extends Cmd
{
    public static string $title = "start HTTP server: Swoole (default) or PHP built-in (dev)";

    public final const string HOST = '0.0.0.0';
    public final const int    PORT = 8000;

    public function handle(): void
    {
        self::printTitle("Run", 34);

        $sub = $this->args['arguments'][1] ?? null;
        match ($sub) {
            'dev'   => $this->runDev(),
            default => $this->runSwoole(),
        };

        self::printTitle("Run", 34);
    }

    // ── Swoole server ─────────────────────────────────────────────────────────

    private function runSwoole(): void
    {
        if (!extension_loaded('swoole')) {
            self::printWarning("Swoole extension is not loaded.");
            self::printInfo("Install swoole: pecl install swoole");
            return;
        }

        $host            = $this->args['options']['host']              ?? self::HOST;
        $port            = (int) ($this->args['options']['port']       ?? self::PORT);
        $workerNum       = $this->args['options']['workers']            ?? null;
        $taskWorkers     = $this->args['options']['tasks']       ?? null;
        $maxRequest      = $this->args['options']['max_request']       ?? null;
        $maxRequestGrace = $this->args['options']['max_request_grace'] ?? null;
        $watcher         = in_array('w', $this->args['flags'] ?? [])
                        || isset($this->args['options']['watcher']);

        $connection = @fsockopen($host, $port);
        if (is_resource($connection)) {
            fclose($connection);
            self::printWarning("Address 'http://$host:$port' is already in use.");
            return;
        }

        self::printSuccess("Swoole server starting at http://$host:$port");
        self::printKeyValue('Root', Kernel::$pathRoot, 6, 34, 90);
        self::printKeyValue('Workers', $workerNum    ?? 'auto', 6, 34, 36);
        self::printKeyValue('Task-workers', $taskWorkers  ?? 'off', 6, 34, 36);
        self::printKeyValue('Max-request', $maxRequest   ?? 'off', 6, 34, 36);
        self::printKeyValue('Max-request-grace', $maxRequestGrace ?? 'off', 6, 34, 36);
        self::printKeyValue('Watcher', $watcher ? 'on' : 'off', 6, 34, 36);

        $router = Router::fromScan(Kernel::$pathRoot);

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_PROC);
        Runtime::boot(RuntimeMode::Swoole);

        // Log — activate Swoole context and HTTP channel
        // ------------------------------------------------
        // Kernel::init() registers channels (sys / http / cli) with ProcessContext
        // and sets 'sys' as default. Here, before the server starts:
        //   1. CoroutineContext — replaces ProcessContext so each coroutine (request)
        //      gets its own isolated storage. All log fields (request_id, user_id …)
        //      set via contextStorage()->set() are scoped to the current coroutine
        //      and never leak across concurrent requests.
        //   2. setDefaultChannel('http') — switches the active channel so every
        //      LoggerFactory::getLogger() and Log::* call writes to 'http'.
        // Both calls happen once before server start; worker processes inherit
        // the state via fork and do not need to call them again.
        LoggerFactory::setContextStorage(new CoroutineContext());
        LoggerFactory::setDefaultChannel('http');
        $router->static(Kernel::$pathPublic);

        $server = new \Swoole\Http\Server($host, $port);

        // Base config from Boot::swooleConfig(), CLI args override
        $bootClass = BaseBoot::getBootClass();
        $config = $bootClass !== '' ? $bootClass::swooleConfig() : [];
        if ($workerNum !== null)       { $config['worker_num']        = (int) $workerNum; }
        if ($taskWorkers !== null)     { $config['task_worker_num']   = (int) $taskWorkers; }
        if ($maxRequest !== null)      { $config['max_request']       = (int) $maxRequest; }
        if ($maxRequestGrace !== null) { $config['max_request_grace'] = (int) $maxRequestGrace; }
        if (!empty($config)) {
            $server->set($config);
        }

        cli_set_process_title(
            "Winter swoole -> server"
            . ($watcher ? '@watch' : '')
            . " [W=" . ($workerNum ?: 'auto') . "]"
            . " [MX_R=" . ($maxRequest ?: 'off') . "]"
            . " [MX_RG=" . ($maxRequestGrace ?: 'off') . "]"
        );

        $workerHandler = static function (\Swoole\Http\Server $server, int $workerId): void {
            cli_set_process_title("Winter swoole -> worker@$workerId");
        };
        $requestHandler = static function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router): void {
            $router->handle(new SwooleRequest($req), new SwooleResponse($res));
        };

        if ($watcher) {
            $memWatcher = new MemoryWatcher();
            $memWatcher->attach($server, $workerHandler);
            $server->on('request', $memWatcher->wrap($requestHandler));
        } else {
            $server->on('workerStart', $workerHandler);
            $server->on('request', $requestHandler);
        }

        $server->start();
    }

    // ── PHP built-in dev server ───────────────────────────────────────────────

    private function runDev(): void
    {
        $isManual = isset($this->args['options']['host']) || isset($this->args['options']['port']);
        $host     = $this->args['options']['host'] ?? self::HOST;
        $port     = isset($this->args['options']['port'])
            ? (int) $this->args['options']['port']
            : self::PORT;

        if ($isManual) {
            $connection = @fsockopen($host, $port);
            if (is_resource($connection)) {
                fclose($connection);
                self::printWarning("Address 'http://$host:$port' is already in use.");
                return;
            }
        } else {
            $basePort = $port;
            for ($i = 0; $i < 10; $i++) {
                $port       = $basePort + $i;
                $connection = @fsockopen($host, $port);
                if (is_resource($connection)) {
                    fclose($connection);
                    self::printWarning("Port $port is busy, trying next...");
                    if ($i === 9) {
                        self::printWarning("No free port found in range {$basePort}–" . ($basePort + 9) . ".");
                        return;
                    }
                } else {
                    break;
                }
            }
        }

        self::printSuccess("Dev server started at http://$host:$port");
        self::printKeyValue('Root', Kernel::$pathPublic, 6, 34, 90);
        passthru('php -S ' . escapeshellarg("$host:$port")
            . ' -t ' . escapeshellarg(Kernel::$pathPublic));
    }

    // ── Help ──────────────────────────────────────────────────────────────────

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Run Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call run            - start Swoole HTTP server", $cl);
        self::print("call run dev        - start PHP built-in dev server", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Swoole options", $cl);
        self::print("--host=              bind host            (default: " . self::HOST . ")", $cl);
        self::print("--port=              bind port            (default: " . self::PORT . ")", $cl);
        self::print("--workers=           number of workers      (default: auto)", $cl);
        self::print("--tasks=      number of task workers (default: off)", $cl);
        self::print("--max_request=       max requests/worker  (default: off)", $cl);
        self::print("--max_request_grace= graceful drain count  (default: off)", $cl);
        self::print("-w / --watcher       enable MemoryWatcher (default: off)", $cl);
        self::printLabel("Swoole options", $cl);

        self::printLabel("Dev options", $cl);
        self::print("--host=              bind host  (default: " . self::HOST . ")", $cl);
        self::print("--port=              bind port  (default: " . self::PORT . ", auto-scan if omitted)", $cl);
        self::printLabel("Dev options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call run");
        self::printInfo("call run --port=8000 --workers=4 -w");
        self::printInfo("call run --max_request=5000 --max_request_grace=500");
        self::printInfo("call run dev");
        self::printInfo("call run dev --port=9000");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/2.0.0/cmd-run");

        self::printTitle("Run Help", $cl);
    }
}
