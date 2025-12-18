<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Http\Router;
use Flytachi\Winter\Kernel\Kernel;

class Mapping extends Cmd
{
    public static string $title = "command mapping control";

    public function handle(): void
    {
        self::printTitle("Mapping", 32);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            $this->buildIsNotExistArg();
        }

        self::printTitle("Mapping", 32);
    }

    private function resolution(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            switch ($this->args['arguments'][1]) {
                case 'show':
                    $this->showArg();
                    break;
                case 'build':
                    $this->buildArg();
                    break;
                case 'clean':
                    $this->cleanArg();
                    break;
                default:
                    self::printMessage("Argument '{$this->args['arguments'][1]}' not found");
                    break;
            }
        }
    }

    private function showArg(): void
    {
        try {
            $declaration = \Flytachi\Winter\Kernel\Factory\Mapping::scanningDeclaration();
            foreach ($declaration->getChildren() as $item) {
                $method = str_pad($item->getMethod() ?: '?', 7);
                $url = str_pad($item->getUrl(), 50);
                $classMethod = $item->getClassName() . '->' . $item->getClassMethod();
                self::printSplit(sprintf("%s /%s %s()", $method, $url, $classMethod), 34);
            }
        } catch (\Throwable $e) {
            self::printMessage("Mapping clean failed", 31);
            if (env('DEBUG', false)) {
                self::printTitle($e->getMessage(), 31);
                self::printSplit($e->getTraceAsString(), 31);
                self::printTitle($e->getMessage(), 31);
            }
        }
    }

    private function buildIsNotExistArg(): void
    {
        try {
            if (!file_exists(Kernel::$pathFileMapping)) {
                (new Router())->generateMappingRoutes();
                self::printMessage("Mapping build success.", 32);
            } else {
                self::printMessage("Mapping already exist.", 32);
            }
        } catch (\Throwable $e) {
            self::printMessage("Mapping build failed", 31);
            if (env('DEBUG', false)) {
                self::printTitle($e->getMessage(), 31);
                self::printSplit($e->getTraceAsString(), 31);
                self::printTitle($e->getMessage(), 31);
            }
        }
    }

    private function buildArg(): void
    {
        try {
            (new Router())->generateMappingRoutes();
            self::printMessage("Mapping build success.", 32);
        } catch (\Throwable $e) {
            self::printMessage("Mapping build failed", 31);
            if (env('DEBUG', false)) {
                self::printTitle($e->getMessage(), 31);
                self::printSplit($e->getTraceAsString(), 31);
                self::printTitle($e->getMessage(), 31);
            }
        }
    }

    private function cleanArg(): void
    {
        try {
            if (file_exists(Kernel::$pathFileMapping)) {
                unlink(Kernel::$pathFileMapping);
                self::printMessage("Mapping clean success.", 32);
            } else {
                self::printMessage(basename(Kernel::$pathFileMapping) . " is not exists.");
            }
        } catch (\Throwable $e) {
            self::printMessage("Mapping clean failed", 31);
            if (env('DEBUG', false)) {
                self::printTitle($e->getMessage(), 31);
                self::printSplit($e->getTraceAsString(), 31);
                self::printTitle($e->getMessage(), 31);
            }
        }
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Mapping Help", $cl);

        self::printLabel("extra mapping [args...] -[flags...]", $cl);
        self::printMessage("args - command", $cl);
        self::print("show - show routes file", $cl);
        self::print("build - build routes file", $cl);
        self::print("clean - clean routes file", $cl);

        self::printTitle("Mapping Help", $cl);
    }
}
