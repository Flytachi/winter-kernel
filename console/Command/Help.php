<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\InstalledVersions;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

final class Help extends Cmd
{
    public static string $title = "list commands and show usage information";

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
        $cl      = 34;
        $project = InstalledVersions::getRootPackage();

        self::printTitle("Winter Framework", $cl);

        // Environment info
        $projectName    = $project['name'] ?? 'unknown';
        $projectVersion = $project['version'] ? $project['version'] : 'dev';
        self::printKeyValue('Project', $projectName . ' (' . $projectVersion . ')', 16, $cl, 36);
        self::printKeyValue('Kernel', InstalledVersions::getPrettyVersion('flytachi/winter-kernel'), 16, $cl, 36);
        self::printKeyValue('PHP', PHP_VERSION, 16, $cl, 36);
        self::printKeyValue('OS', PHP_OS_FAMILY . ' / ' . PHP_OS, 16, $cl, 36);
        self::printKeyValue('SAPI', PHP_SAPI, 16, $cl, 36);
        self::printKeyValue('Project root', Kernel::$pathRoot, 16, $cl, 90);

        self::printDivider($cl);

        // Commands list
        self::printLabel("Available Commands", $cl);
        foreach (glob(__DIR__ . '/*.php') as $cmdFile) {
            $name  = basename($cmdFile, '.php');
            $title = ('Flytachi\Winter\Console\Command\\' . $name)::$title;
            self::printBadge(strtolower($name), $title, $cl, 36);
        }
        self::printDivider($cl);

        self::printInfo("Run 'call help <command>' for detailed usage.");
        self::printInfo("Docs: https://winterframe.net/docs/console-overview");

        self::printTitle("Winter Framework", $cl);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call help              - list all commands + environment info", $cl);
        self::print("call help <command>    - show detailed help for a command", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call help make");
        self::printInfo("call help run");

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/console-overview");

        self::printTitle("Help", $cl);
    }
}
