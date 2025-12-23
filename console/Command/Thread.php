<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Process\Core\Dispatchable;

class Thread extends Cmd
{
    public static string $title = "command thread runnable control";

    public function handle(): void
    {
        self::printTitle("Run", 32);

        if (
            count($this->args['arguments']) > 1
        ) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Run", 32);
    }

    private function resolution(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            switch ($this->args['arguments'][1]) {
                case 'run':
                    $this->threadArg();
                    break;
                default:
                    self::printMessage("Argument '{$this->args['arguments'][1]}' not found");
                    break;
            }
        }
    }

    private function threadArg(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            if (
                isset($this->args['arguments'][2])
                && $this->args['arguments'][2]
            ) {
                $param = $this->args['arguments'][2];
                $class = str_replace(
                        '/',
                        '\\',
                        implode('/', array_map(fn($word) => ucfirst($word), explode(
                            '/',
                            str_replace('.', '/', $param)
                        )))
                    );
                if (class_exists($class)) {
                    if (
                        array_key_exists(0, $this->args['flags'])
                        && $this->args['flags'][0] == 'd'
                    ) {
                        $this->threadRunnableToBack($class);
                    } else {
                        $this->threadRunnable($class);
                    }
                } else {
                    self::printMessage("The specified class '{$class}' was not found");
                }
            } else {
                self::printMessage("Not classname");
            }
        } else {
            self::printMessage("Asynchronous pcntl signals are not enabled", 31);
        }
    }

    /**
     * @param class-string<Dispatchable> $class
     * @return void
     */
    private function threadRunnable(string $class): void
    {
        self::printMessage("{$class} start", 32);
        ($class)::start();
        self::printMessage("{$class} end", 32);
    }

    /**
     * @param class-string<Dispatchable> $class
     * @return void
     */
    private function threadRunnableToBack(string $class): void
    {
//        // Cache
//        $cache = null;
//        if (array_key_exists('cache', $this->args['options'])) {
//            $filePath = Kernel::$pathStorageCache . '/' . $this->args['options']['cache'];
//            if (is_file($filePath)) {
//                $cache = $this->args['options']['cache'];
//            }
//        }
//
//        $processId = exec(sprintf(
//            "php extra run thread --name='%s' %s > %s 2>&1 & echo $!",
//            $class,
//            ($cache ? "--cache='{$cache}'" : ''),
//            "/dev/null"
//        ));
//        self::printMessage("$class started in background!", 32);
//        self::printMessage("PID: " . $processId, 32);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Thread Help", $cl);

        self::printLabel("extra thread [args...] -[flags...] --[options...]", $cl);
        self::printMessage("args - command", $cl);
        self::print("run - run the 'Thread' task in the foreground (to run in the background use -d)", $cl);

        // thread
        self::printLabel("run", $cl);
        self::printMessage("flags - additional args for running", $cl);
        self::print("d - start process in background", $cl);
        self::printMessage("options - data for running", $cl);
        self::print("name - class name, with namespaces(example 'main.threads.exampleJob')", $cl);
        self::print("cache - name cache file used in process (serializable)", $cl);
        self::printLabel("run", $cl);

        self::printTitle("Thread Help", $cl);
    }
}
