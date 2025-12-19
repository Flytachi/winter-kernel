<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Cfg extends Cmd
{
    public static string $title = "command config control";
    private string $templatePath;

    public function handle(): void
    {
        self::printTitle("Cfg", 32);
        $this->templatePath = dirname(__DIR__) . '/Template';

        if (
            count($this->args['arguments']) > 1
        ) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Cfg", 32);
    }

    private function resolution(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            switch ($this->args['arguments'][1]) {
                case 'init':
                    $this->initArg();
                    break;
                case 'env':
                    $this->envArg();
                    break;
                case 'docker':
                    $this->dockerArg();
                    break;
                case 'openapi':
                    $this->openapiArg();
                    break;
                default:
                    self::printMessage("Argument '{$this->args['arguments'][1]}' not found");
                    break;
            }
        }
    }

    private function initArg(): void
    {
        $filePath = Kernel::$pathRoot . '/composer.json';
        if (file_exists($filePath) && is_readable($filePath)) {
            $projectData = json_decode(
                file_get_contents($filePath) ?: '',
                true
            );

            $extra = $projectData['extra'] ?? [];

            $extra['project']['name'] = basename(Kernel::$pathRoot);
            $extra['project']['version'] = $extra['project']['version'] ?? '1.0.0';
            $extra['project']['description'] = $extra['project']['description'] ?? 'Winter framework based';

            $projectData['extra'] = $extra;

            $json = json_encode($projectData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents($filePath, $json . PHP_EOL);
        }

        $this->envCreate();
    }

    private function keyArg(): void
    {
        if (in_array('g', $this->args['flags'])) {
            $this->keyGenerate();
        }
        if (in_array('s', $this->args['flags'])) {
            $this->keyShow();
        }
    }

    private function keyGenerate(): void
    {
//        self::printLabel('NEW EXTRA KEY', 34);
//        self::printSplit('OLD: ' . EXTRA_KEY, 34);
//
//        $pathDefines = PATH_APP . '/defines.php';
//        $defines = file_get_contents($pathDefines);
//        $newKey = md5(uniqid(rand(), 1))  . '-' . md5(basename(PATH_ROOT)) . '-' . sha1(uniqid(rand(), 1));
//        $newDefines = str_replace(EXTRA_KEY, $newKey, $defines);
//        file_put_contents($pathDefines, $newDefines);
//
//        self::printSplit('NEW: ' . $newKey, 34);
//        self::printLabel('NEW EXTRA KEY', 34);
    }

    private function keyShow(): void
    {
//        self::printLabel('EXTRA KEY', 34);
//        self::printSplit(EXTRA_KEY, 34);
//        self::printLabel('EXTRA KEY', 34);
    }

    private function envArg(): void
    {
        if (in_array('i', $this->args['flags'])) {
            $this->envCreate();
        }
        if (in_array('s', $this->args['flags'])) {
            $this->envShow();
        }
    }

    private function envCreate(): void
    {
        if (!file_exists(Kernel::$pathEnv)) {
            if (
                copy(
                    $this->templatePath . '/Build/env',
                    Kernel::$pathEnv
                )
            ) {
                self::printMessage("File '.env' is created.", 32);
            } else {
                self::printMessage("File '.env' dont created.", 31);
            }
        } else {
            self::printMessage("File '.env' is already exist.");
        }

        $this->phpstormMetta();
    }

    private function phpstormMetta(): void
    {
        try {
            if (is_dir(Kernel::$pathRoot . '/vendor')) {
                $metaPath = Kernel::$pathRoot . '/vendor/.phpstorm.meta';
                if (!is_dir($metaPath)) {
                    mkdir($metaPath, 0777, true);
                }
                $metaPath = $metaPath . '/.phpstorm.meta.php';
                if (!file_exists($metaPath)) {
                    copy(
                        $this->templatePath . '/Build/phpstormMeta',
                        $metaPath
                    );
                }
            }
        } catch (\Throwable) {
        }
    }

    private function envShow(): void
    {
        if (in_array('file', $this->args['options'])) {
            if (!is_file(Kernel::$pathEnv)) {
                self::printLabel(Kernel::$pathEnv, 34);
                self::printSplit(file_get_contents(Kernel::$pathEnv), 34);
                self::printLabel(Kernel::$pathEnv, 34);
            } else {
                self::printMessage("File '" . Kernel::$pathEnv . "' does not exist.");
            }
        } else {
            self::printLabel('ENVIRONMENT', 34);
            foreach ($_ENV as $key => $value) {
                self::print("{$key} = {$value}", 34);
            }
            self::printLabel('ENVIRONMENT', 34);
        }
    }

    private function dockerArg(): void
    {
        multiCopy($this->templatePath . '/Docker', Kernel::$pathRoot);
        self::printMessage("Folder 'docker' is created.", 32);
        self::printMessage("File 'docker-compose' is created.", 32);
        self::printMessage("File 'Dockerfile' is created.", 32);
    }

    private function openapiArg(): void
    {
        if (!file_exists(Kernel::$pathMain . '/OpenApiController.php')) {
            $code = file_get_contents($this->templatePath . '/Packages/OpenApiTemplate');
            $fp = fopen(Kernel::$pathMain . '/OpenApiController.php', "x");
            fwrite($fp, $code);
            fclose($fp);
            self::printMessage("File 'OpenApiController' is created.", 32);
        } else {
            self::printMessage("File 'OpenApiController' is already exist.");
        }
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Cfg Help", $cl);

        self::printLabel("extra cfg [args...] -[flags...] --[options...]", $cl);
        self::printMessage("args - command", $cl);
        self::print("key - project unique key", $cl);
        self::print("env - project environment", $cl);
        self::print("docker - create docker configuration file", $cl);
        self::print("openapi - create openapi collection api controller", $cl);

        // key
        self::printLabel("key", $cl);
        self::printMessage("flags - selection additional to be action", $cl);
        self::print("g - (re)generate project unique key", $cl);
        self::print("s - show project unique key", $cl);
        self::printLabel("key", $cl);

        // env
        self::printLabel("env", $cl);
        self::printMessage("flags - selection additional to be action", $cl);
        self::print("i - create configuration file", $cl);
        self::print("s - show configuration file", $cl);
        self::printMessage("options - additional option to be action", $cl);
        self::print("file - project environment file", $cl);
        self::printLabel("env", $cl);

        self::printTitle("Cfg Help", $cl);
    }
}
