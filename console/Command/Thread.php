<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Process\Core\Dispatchable;

class Thread extends Cmd
{
    public static string $title = "run Dispatchable thread tasks in foreground or background";

    public function handle(): void
    {
        self::printTitle("Thread", 34);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Thread", 34);
    }

    private function resolution(): void
    {
        switch ($this->args['arguments'][1] ?? '') {
            case 'run':
                $this->threadArg();
                break;
            case 'list':
                $this->listArg();
                break;
            default:
                self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                self::printInfo("Run 'call thread --help' to see available commands.");
                break;
        }
    }

    private function threadArg(): void
    {
        if (!extension_loaded('pcntl')) {
            self::printWarning("Extension 'pcntl' is not loaded — async signals unavailable.");
            return;
        }

        pcntl_async_signals(true);

        $param = $this->args['arguments'][2] ?? '';
        if (!$param) {
            self::printWarning("Class name is required.");
            self::printInfo("Example: call thread run main.threads.ExampleJob");
            return;
        }

        $class = str_replace(
            '/',
            '\\',
            implode('/', array_map(
                fn($word) => ucfirst($word),
                explode('/', str_replace('.', '/', $param))
            ))
        );

        if (!class_exists($class)) {
            self::printWarning("Class not found: $class");
            self::printInfo("Check the dot-notation path and make sure the class is autoloaded.");
            return;
        }

        $inBackground = in_array('d', $this->args['flags']);
        if ($inBackground) {
            $this->threadRunnableToBack($class);
        } else {
            $this->threadRunnable($class);
        }
    }

    private function listArg(): void
    {
        $loaders      = ClassLoader::getRegisteredLoaders();
        $loader       = reset($loaders);
        $namespaceMap = $loader->getPrefixesPsr4();

        $vendorPath = realpath(Kernel::$pathRoot . '/vendor');

        $threads = [];
        foreach ($namespaceMap as $nsPrefix => $dirs) {
            foreach ($dirs as $dir) {
                $realDir = realpath($dir);
                if (!$realDir || !str_starts_with($realDir, Kernel::$pathRoot)) {
                    continue;
                }
                if ($vendorPath && str_starts_with($realDir, $vendorPath)) {
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
                            $threads[] = $ref;
                        }
                    } catch (\ReflectionException) {
                        // skip unresolvable classes
                    }
                }
            }
        }

        self::printLabel("Available Threads", 34);
        if (empty($threads)) {
            self::printWarning("No Dispatchable classes found.");
            self::printInfo("Create one that implements Dispatchable.");
        } else {
            foreach ($threads as $ref) {
                $dotName = str_replace('\\', '.', $ref->getName());
                self::printBadge($dotName, 'Dispatchable', 34, 36);
            }
        }
        self::printLabel("Available Threads", 34);
    }

    /**
     * @param class-string<Dispatchable> $class
     */
    private function threadRunnable(string $class): void
    {
        self::printInfo("Starting: $class");
        ($class)::start();
        self::printSuccess("Finished: $class");
    }

    /**
     * @param class-string<Dispatchable> $class
     */
    private function threadRunnableToBack(string $class): void
    {
        $pid = ($class)::dispatch();
        self::printSuccess("Dispatched: $class");
        self::printKeyValue("PID", (string) $pid, 10, 34, 32);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Thread Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call thread [command] [class] -[flags]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list',                        'list all Dispatchable classes',  $cl, 36);
        self::printBadge('run <dot.notation.Class>',    'run task in foreground',         $cl, 36);
        self::printBadge('run <dot.notation.Class> -d', 'dispatch to background',         $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-d", "dispatch task as background process", 10, $cl, 36);
        self::printLabel("Flags", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call thread list");
        self::printInfo("call thread run main.threads.ExampleJob");
        self::printInfo("call thread run main.threads.ExampleJob -d");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/#");

        self::printTitle("Thread Help", $cl);
    }
}
