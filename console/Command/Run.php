<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Run extends Cmd
{
    public static string $title = "command runnable control";
    public final const string HOST = '0.0.0.0';
    public final const int PORT = 8000;

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
                case 'serve':
                    $this->serveArg();
                    break;
                case 'script':
                    $this->scriptArg();
                    break;
                default:
                    self::printMessage("Argument '{$this->args['arguments'][1]}' not found");
                    break;
            }
        }
    }

    private function serveArg(): void
    {
        $host = (isset($this->args['options']['host'])) ? $this->args['options']['host'] : self::HOST;
        $port = (isset($this->args['options']['port'])) ? (int) $this->args['options']['port'] : self::PORT;
        $connection = @fsockopen($host, $port);

        if (is_resource($connection)) {
            self::printMessage("Permission denied, 'http://{$host}:{$port}' is already busy!");
            fclose($connection);
        } else {
            self::printMessage("Starting the server to 'http://" . $host . ':' . $port . "'", 32);
            exec("php -S {$host}:{$port} -t " . Kernel::$pathPublic);
        }
    }

    private function scriptArg(): void
    {
        if (array_key_exists(2, $this->args['arguments'])) {
            $classname = str_replace(
                '/',
                '\\',
                implode('/', array_map(fn($word) => ucfirst($word), explode(
                    '/',
                    str_replace('.', '/', ucwords($this->args['arguments'][2]))
                )))
            ) . 'Cmd';
            $name = explode('\\', $classname);
            $name = $name[count($name) - 1];
            if (!class_exists($classname)) {
                self::printMessage("Script named '{$name}' not found ({$classname}).");
            } else {
                $classname::script([
                    'arguments' => array_values(array_slice($this->args['arguments'], 2)),
                    'options' => $this->args['options'],
                    'flags' => $this->args['flags'],
                ]);
            }
        } else {
            self::printMessage("Script name not specified.");
        }
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Run Help", $cl);

        self::printLabel("extra run [args...] -[flags...] --[options...]", $cl);
        self::printMessage("args - command", $cl);
        self::print("serve - starting the server (default address '" . self::HOST . ':' . self::PORT . "')", $cl);
        self::print("script - run a custom command (specify the script name)", $cl);

        // serve
        self::printLabel("serve", $cl);
        self::printMessage("options - selection for action", $cl);
        self::print("host - hostname (default " . self::HOST . ")", $cl);
        self::print("port - port (default " . self::PORT . ")", $cl);
        self::printLabel("serve", $cl);

        self::printTitle("Run Help", $cl);
    }
}
