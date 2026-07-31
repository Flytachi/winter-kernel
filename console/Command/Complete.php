<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Core;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Console\Inc\CmdCustom;
use Flytachi\Winter\K2\Collector\SubclassCollector;
use Flytachi\Winter\K2\Core\ClassScanner;
use Flytachi\Winter\K2\Process\Daemon\Daemon as DaemonUnit;
use Flytachi\Winter\K2\Process\Process as ProcessUnit;

class Complete extends Cmd
{
    public static string $title = "shell completion endpoint (internal)";

    /** Static completion map: command (+ optional subcommand) → suggestions with descriptions */
    private static array $map = [
        ''          => [],   // level 1: filled dynamically (command names + titles)

        // --- make ---
        'make' => [
            '-c:Controller — API controller',
            '-s:Service — business logic service',
            '-m:Middleware — HTTP middleware',
            '-r:Repository — data access repository',
            '-t:Store — Redis-backed store',
            '-e:Entity — ORM entity / model',
            '-d:Dto — Data Transfer Object',
            '-q:Request — validated request object',
            '-p:Response — custom HTTP response',
            '-P:Process — long-running process',
            '-N:Daemon — background daemon',
            '-D:DbConfig — database configuration',
            '-R:RedisConfig — Redis configuration',
            '-n:Cmd — custom console command',
            '--mvc:wrap output path in MVC category folders',
        ],

        // --- cfg ---
        'cfg' => [
            'init:initialize project (.env + key)',
            'key:manage WINTER_KEY',
            'env:manage .env file',
            'docker:scaffold Docker files (default: fpm)',
            'completion:install shell tab completion',
        ],
        'cfg key'        => ['-g:generate WINTER_KEY', '-s:show current key'],
        'cfg env'        => ['-i:create .env from template', '-s:show loaded env vars', '--file:show raw .env file'],
        'cfg docker'     => [],
        'cfg completion' => ['-i:install globally (once per machine)', '-if:force reinstall', '-f:force flag'],

        // --- run ---
        'run' => [
            'dev:start PHP built-in dev server',
            '--host=:bind host (default: 0.0.0.0)',
            '--port=:bind port (default: 8000)',
            '--workers=:number of Swoole workers',
            '--tasks=:number of Swoole task workers',
            '--max_request=:max requests per worker',
            '--max_request_grace=:graceful drain count',
            '-w:enable DevWatcher (memory + hot-reload)',
        ],
        'run dev' => [
            '--host=:bind host (default: 0.0.0.0)',
            '--port=:bind port (default: 8000)',
        ],

        // --- mapping ---
        'mapping' => [
            'build:scan controllers and write the route cache file',
            'clean:delete the route cache file',
            'show:list all registered routes',
        ],
        'mapping show' => [],   // takes optional URL pattern as argument

        // --- di ---
        'di' => [
            'build:scan project once — write the DI cache and generate #[Async] proxies',
            'clean:delete the DI cache and every generated proxy',
            'show:list classes in the DI cache (or a live scan if absent)',
            'async:list #[Async] methods and whether their proxy is built',
        ],
        'di show' => [],   // takes optional FQCN substring as argument
        'di async' => [],  // takes optional FQCN substring as argument

        // --- storage ---
        'storage' => [
            'init:create storage folders',
            'clean:delete contents of storage folders',
        ],
        'storage init'  => ['-s:storage', '-c:storage/cache', '-l:storage/logs'],
        'storage clean' => ['-s:storage', '-c:storage/cache', '-l:storage/logs'],

        // --- process / proc ---
        'process' => [
            'list:list all processes with live state',
        ],

        // --- daemon / dmn ---
        'daemon' => [
            'list:list all daemons with live state',
        ],

        // --- schedule / sch ---
        'schedule' => [
            'list:list all #[Scheduled] tasks and cadence',
            'start:run the scheduler (foreground; -d for background)',
            'stop:send graceful stop (SIGTERM)',
            'status:scheduler run state + task count',
        ],
        'schedule start' => ['-d:run detached in background'],

        // --- db ---
        'db' => [
            'ping:check DB connection and latency',
            'migrate:run migrations against connected databases',
            'sql:preview generated SQL without executing',
        ],
        'db ping'    => [],   // auto-scans project + all plugins
        'db migrate' => [
            '-s:schemes only', '-t:tables only', '-i:indexes only', '-c:constraints only',
            '--plugin=:target a single plugin', '--plugins:target all plugins',
        ],
        'db sql' => [
            '-s:schemes only', '-t:tables only', '-i:indexes only', '-c:constraints only',
            '--plugin=:target a single plugin', '--plugins:target all plugins',
        ],

        // --- script / sc ---
        'script'   => ['list:list all Cmd/CmdCustom scripts'],
        'sc'       => ['list:list all Cmd/CmdCustom scripts'],

        // --- misc ---
        'help'     => [],   // filled dynamically (command names + titles)
        'complete' => [],
    ];

    public function handle(): void
    {
        $line  = getenv('COMP_LINE') ?: '';
        $point = (int) (getenv('COMP_POINT') ?: strlen($line));

        // Slice line at cursor, tokenize
        $input   = substr($line, 0, $point);
        $tokens  = array_values(array_filter(explode(' ', $input), fn($t) => $t !== ''));
        // Current (possibly partial) word the user is typing
        $current = str_ends_with($input, ' ') ? '' : (array_pop($tokens) ?? '');

        // tokens[0] = script name, tokens[1] = cmd, tokens[2] = sub, tokens[3] = action
        $cmd = isset($tokens[1]) ? strtolower($tokens[1]) : null;
        $sub = isset($tokens[2]) ? strtolower($tokens[2]) : null;
        $act = isset($tokens[3]) ? strtolower($tokens[3]) : null;

        $suggestions = $this->suggest($cmd, $sub, $act, $current);

        if (!empty($suggestions)) {
            echo implode("\n", $suggestions) . "\n";
        }
    }

    private function suggest(?string $cmd, ?string $sub, ?string $act, string $current): array
    {
        // Level 1: no command typed yet → all command names
        if ($cmd === null) {
            return $this->filter($this->getCommandNames(), $current);
        }

        // Resolve alias to canonical name
        $resolved = strtolower(Core::getAliases()[$cmd] ?? ucfirst($cmd));

        // Level 2+: check "cmd sub" key first, then just "cmd"
        $key = $sub !== null ? "$resolved $sub" : $resolved;

        if (array_key_exists($key, self::$map)) {
            $base = self::$map[$key];
        } elseif (array_key_exists($resolved, self::$map)) {
            $base = self::$map[$resolved];
        } else {
            return [];
        }

        // sc/script: append discovered Cmd class names
        if (in_array($resolved, ['script', 'sc']) && $sub === null) {
            $base = array_merge($base, $this->getScriptClasses());
        }

        // process: list + classes at top level; once a class is selected,
        // suggest lifecycle actions, then flags per action.
        if ($resolved === 'process' && $sub !== null && $sub !== 'list') {
            if ($act === null) {
                $base = [
                    'start:start (foreground; -d for background)',
                    'stop:send graceful stop (SIGTERM)',
                    'status:show status (-v for detail)',
                    '-d:start detached in background',
                ];
            } elseif ($act === 'status') {
                $base = ['-v:detailed status (resources + workers)'];
            } elseif ($act === 'start') {
                $base = ['-d:start detached in background'];
            } else {
                $base = [];
            }
        } elseif ($resolved === 'process' && $sub === null) {
            $base = array_merge($this->getProcessClasses(), $base);
        }

        // daemon: list + classes at top level; once a class is selected,
        // suggest lifecycle actions, then flags per action.
        if ($resolved === 'daemon' && $sub !== null && $sub !== 'list') {
            if ($act === null) {
                $base = [
                    'start:supervise (foreground; -d for background)',
                    'stop:graceful stop (drains the fleet)',
                    'status:show status + worker fleet',
                    '-d:supervise detached in background',
                ];
            } elseif ($act === 'status') {
                $base = ['-v:also master resource usage'];
            } elseif ($act === 'start') {
                $base = ['-d:supervise detached in background'];
            } else {
                $base = [];
            }
        } elseif ($resolved === 'daemon' && $sub === null) {
            $base = array_merge($this->getDaemonUnitClasses(), $base);
        }

        // help: suggest command names
        if ($resolved === 'help' && $sub === null) {
            $base = $this->getCommandNames();
        }

        return $this->filter($base, $current);
    }

    private function filter(array $items, string $current): array
    {
        if ($current === '') {
            return $items;
        }
        $needle = strtolower($current);
        return array_values(array_filter($items, function (string $s) use ($needle): bool {
            $word = strstr($s, ':', true) ?: $s;
            return str_starts_with(strtolower($word), $needle);
        }));
    }

    private function getCommandNames(): array
    {
        $names = [];
        foreach (glob(__DIR__ . '/*.php') as $file) {
            $name = strtolower(basename($file, '.php'));
            if ($name === 'complete') {
                continue;
            }
            $class = 'Flytachi\\Winter\\Console\\Command\\' . ucfirst($name);
            $title = (defined("$class::title") || isset($class::$title)) ? $class::$title : '';
            $names[$name] = $title ? "$name:$title" : $name;
        }
        foreach (Core::getAliases() as $alias => $cmd) {
            $names[$alias] = "$alias:alias for $cmd";
        }
        return array_values($names);
    }

    private function getScriptClasses(): array
    {
        $collector = new SubclassCollector(Cmd::class, CmdCustom::class);
        ClassScanner::scan($collector);

        return array_map(
            fn(\ReflectionClass $ref) => str_replace('\\', '.', $ref->getName()),
            $collector->getResult()
        );
    }

    private function getProcessClasses(): array
    {
        $collector = new SubclassCollector(ProcessUnit::class);
        ClassScanner::scan($collector);

        $bare = array_filter(
            $collector->getResult(),
            static fn(\ReflectionClass $ref) => !$ref->isSubclassOf(DaemonUnit::class)
        );

        return array_map(
            fn(\ReflectionClass $ref) => str_replace('\\', '.', $ref->getName()),
            $bare
        );
    }

    private function getDaemonUnitClasses(): array
    {
        $collector = new SubclassCollector(DaemonUnit::class);
        ClassScanner::scan($collector);

        return array_map(
            fn(\ReflectionClass $ref) => str_replace('\\', '.', $ref->getName()),
            $collector->getResult()
        );
    }

    public static function help(): void
    {
    }
}
