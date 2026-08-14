<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Console\Stereotype\CmdCustom;
use Flytachi\Winter\Kernel\Collector\SubclassCollector;
use Flytachi\Winter\Kernel\Core\ClassScanner;

final class Script extends Cmd
{
    public static string $title = "run or list custom Cmd scripts";

    public function handle(): void
    {
        self::printTitle("Script", 34);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Script", 34);
    }

    private function resolution(): void
    {
        switch ($this->args['arguments'][1] ?? '') {
            case 'list':
                $this->listArg();
                break;
            default:
                $this->runArg($this->args['arguments'][1]);
                break;
        }
    }

    private function runArg(string $input): void
    {
        $classname = str_replace(
            '/',
            '\\',
            implode('/', array_map(
                fn($word) => ucfirst($word),
                explode('/', str_replace('.', '/', $input))
            ))
        );
        $name = basename(str_replace('\\', '/', $classname));

        if (!class_exists($classname)) {
            self::printWarning("Class '$name' not found.");
            self::printInfo("Resolved: $classname");
            self::printInfo("Run 'call sc list' to see available scripts.");
        } elseif (!is_subclass_of($classname, Cmd::class) && !is_subclass_of($classname, CmdCustom::class)) {
            self::printWarning("Class '$name' does not extend Cmd or CmdCustom.");
            self::printInfo("Resolved: $classname");
        } else {
            self::printInfo("Running: $name");
            $classname::script([
                'arguments' => array_values(array_slice($this->args['arguments'], 1)),
                'options'   => $this->args['options'],
                'flags'     => $this->args['flags'],
            ]);
        }
    }

    private function listArg(): void
    {
        $collector = new SubclassCollector(Cmd::class, CmdCustom::class);
        ClassScanner::scan($collector);
        $scripts = $collector->getResult();

        self::printLabel("Available Scripts", 34);
        if (empty($scripts)) {
            self::printWarning("No custom scripts found.");
            self::printInfo("Create one with: call make .MyScript -n");
        } else {
            foreach ($scripts as $ref) {
                $dotName = str_replace('\\', '.', $ref->getName());
                $type    = $ref->isSubclassOf(Cmd::class) ? 'Cmd' : 'CmdCustom';
                self::printBadge($dotName, $type, 34, 36);
            }
        }
        self::printLabel("Available Scripts", 34);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Script Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call script <command> [args] --[options]", $cl);
        self::print("call sc     <command> [args] --[options]    (alias)", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('<dot.notation.ClassName>', 'run script directly', $cl, 36);
        self::printBadge('list', 'list all Cmd/CmdCustom scripts', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);

        self::print("Resolves dot-notation to a fully qualified class,", $cl);
        self::print("verifies it extends Cmd or CmdCustom, then runs ::script()", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call script list");
        self::printInfo("call script my.custom.Task");
        self::printInfo("call script app.console.SeedUsers");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-script");

        self::printTitle("Script Help", $cl);
    }
}
