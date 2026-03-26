<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\Console\Core;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Console\Inc\CmdCustom;
use Flytachi\Winter\Kernel\Kernel;

class Complete extends Cmd
{
    public static string $title = "shell completion endpoint (internal)";

    /** Static completion map: command (+ optional subcommand) → suggestions with descriptions */
    private static array $map = [
        ''          => [],   // level 1: filled dynamically (command names + titles)
        'make'      => [
            '-a:create all (controller, service, repository, model)',
            '-c:create controller',
            '-s:create service',
            '-m:create model',
            '-r:create repository',
            '-t:create test',
            '-e:create entity',
            '-d:create DTO',
            '-q:create query',
            '-p:create pipe',
            '-J:create job',
            '-P:create policy',
            '-N:create notification',
            '-W:create command (Cmd)',
            '-D:create middleware',
            '-R:create resource',
            '-n:use namespace only (no suffix)',
            '--mvc:place in mvc subdirectory',
        ],
        'cfg'       => [
            'init:initialize project (.env + key)',
            'key:manage WINTER_KEY',
            'env:manage .env file',
            'docker:scaffold Docker files',
            'openapi:create OpenAPI controller stub',
            'completion:install shell tab completion',
        ],
        'cfg key'        => ['-g:generate WINTER_KEY', '-s:show current key'],
        'cfg env'        => ['-i:create .env from template', '-s:show loaded env vars', '--file:show raw .env file'],
        'cfg completion' => ['-i:install globally (once per machine)', '-if:force update', '-f:force flag'],
        'serve'          => ['--host=:bind host', '--port=:bind port'],
        'help'           => [],   // filled dynamically (command names + titles)
        'sc'             => ['list:list all available scripts'],
        'script'         => ['list:list all available scripts'],
        'complete'       => [],
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
                    } catch (\ReflectionException) {}
                }
            }
        }
        return $classes;
    }

    public static function help(): void {}
}
