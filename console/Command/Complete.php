<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\Console\Core;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Console\Inc\CmdCustom;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Process\Core\Dispatchable;

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
            '-J:Job — async queue job',
            '-P:Process — long-running process',
            '-N:Daemon — background daemon',
            '-W:WebSocket — WebSocket handler',
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
        'cfg docker'     => ['--fpm:PHP-FPM + Nginx mode (default)', '--swoole:Swoole HTTP server mode'],
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
            '-w:enable MemoryWatcher',
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

        // --- storage ---
        'storage' => [
            'init:create storage folders',
            'clean:delete contents of storage folders',
        ],
        'storage init'  => ['-s:storage', '-c:storage/cache', '-l:storage/logs'],
        'storage clean' => ['-s:storage', '-c:storage/cache', '-l:storage/logs'],

        // --- thread / th ---
        'thread' => [
            'list:list all Dispatchable classes',
            'run:run task in foreground',
        ],
        'thread run' => ['-d:dispatch as background process'],

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

        // tokens[0] = script name, tokens[1] = cmd, tokens[2] = sub
        $cmd = isset($tokens[1]) ? strtolower($tokens[1]) : null;
        $sub = isset($tokens[2]) ? strtolower($tokens[2]) : null;

        $suggestions = $this->suggest($cmd, $sub, $current);

        if (!empty($suggestions)) {
            echo implode("\n", $suggestions) . "\n";
        }
    }

    private function suggest(?string $cmd, ?string $sub, string $current): array
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

        // thread run: append discovered Dispatchable class names
        if ($resolved === 'thread' && $sub === 'run') {
            $base = array_merge($this->getDispatchableClasses(), $base);
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
        return array_values(array_filter($items, function (string $s) use ($current): bool {
            $word = strstr($s, ':', true) ?: $s;
            return str_starts_with($word, $current);
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
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader  = reset($loaders);
        $nsMap   = $loader->getPrefixesPsr4();
        $vendor  = realpath(Kernel::$pathRoot . '/vendor');
        $classes = [];

        foreach ($nsMap as $nsPrefix => $dirs) {
            foreach ($dirs as $dir) {
                $realDir = realpath($dir);
                if (!$realDir || !str_starts_with($realDir, Kernel::$pathRoot)) {
                    continue;
                }
                if ($vendor && str_starts_with($realDir, $vendor)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($realDir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($files as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $relative  = substr($file->getRealPath(), strlen($realDir));
                    $relative  = substr(ltrim(str_replace('/', '\\', $relative), '\\'), 0, -4);
                    $className = rtrim($nsPrefix, '\\') . '\\' . $relative;
                    if (!class_exists($className)) {
                        continue;
                    }
                    try {
                        $ref = new \ReflectionClass($className);
                        if (
                            !$ref->isAbstract()
                            && ($ref->isSubclassOf(Cmd::class) || $ref->isSubclassOf(CmdCustom::class))
                        ) {
                            $classes[] = str_replace('\\', '.', $className);
                        }
                    } catch (\ReflectionException) {
                    }
                }
            }
        }
        return $classes;
    }

    private function getDispatchableClasses(): array
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader  = reset($loaders);
        $nsMap   = $loader->getPrefixesPsr4();
        $vendor  = realpath(Kernel::$pathRoot . '/vendor');
        $classes = [];

        foreach ($nsMap as $nsPrefix => $dirs) {
            foreach ($dirs as $dir) {
                $realDir = realpath($dir);
                if (!$realDir || !str_starts_with($realDir, Kernel::$pathRoot)) {
                    continue;
                }
                if ($vendor && str_starts_with($realDir, $vendor)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($realDir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($files as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $relative  = substr($file->getRealPath(), strlen($realDir));
                    $relative  = substr(ltrim(str_replace('/', '\\', $relative), '\\'), 0, -4);
                    $className = rtrim($nsPrefix, '\\') . '\\' . $relative;
                    if (!class_exists($className)) {
                        continue;
                    }
                    try {
                        $ref = new \ReflectionClass($className);
                        if (!$ref->isAbstract() && $ref->implementsInterface(Dispatchable::class)) {
                            $classes[] = str_replace('\\', '.', $className);
                        }
                    } catch (\ReflectionException) {
                    }
                }
            }
        }
        return $classes;
    }

    public static function help(): void
    {
    }
}
