<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Help extends Cmd
{
    public static string $title = "command reference";

    public function handle(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            $this->resolution($this->args['arguments'][1]);
        } else {
            $this->list();
        }
    }

    private function resolution(string $cmdName): void
    {
        $cmd = ucwords($cmdName);
        ('Flytachi\Winter\Console\Command\\' . $cmd)::help();
    }

    public function list(): void
    {
        $kernel = Kernel::info();
        $project = Kernel::projectInfo();
        self::printTitle("Winter Help", 34);
        if (!empty($project['extra']) && !empty($project['extra']['project'])) {
            $projectName = $project['extra']['project']['name'] ?? 'unknown';
            $projectVersion = $project['extra']['project']['version']
                ? ' (' . $project['extra']['project']['version'] . ')'
                : '';
            self::print("Project: {$projectName}{$projectVersion}", 34);
        }
        self::print("Kernel Version: " . $kernel['version'], 34);
        self::print("PHP Version: " . PHP_VERSION, 34);
        self::print("OS: " . PHP_OS_FAMILY . DIRECTORY_SEPARATOR . PHP_OS, 34);
        self::print("SAPI: " . PHP_SAPI, 34);

        self::printLabel("Commands", 34);
        foreach (glob(__DIR__ . '/*.php') as $cmdFile) {
            $name = basename($cmdFile, '.php');
            self::printMessage(
                strtolower($name)
                . " - " .  ('Flytachi\Winter\Console\Command\\' . $name)::$title,
                34
            );
        }
        self::printTitle("Winter Help", 34);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Make Help", $cl);

        self::printLabel("extra help [args...]", $cl);
        self::printMessage("args - command name", $cl);

        self::printTitle("Make Help", $cl);
    }
}
