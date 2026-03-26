<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Cfg extends Cmd
{
    public static string $title = "manage project configuration, environment and keys";
    private string $templatePath;

    public function handle(): void
    {
        self::printTitle("Config", 34);
        $this->templatePath = dirname(__DIR__) . '/Template';

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Config", 34);
    }

    private function resolution(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            switch ($this->args['arguments'][1]) {
                case 'init':
                    $this->initArg();
                    break;
                case 'key':
                    $this->keyArg();
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
                    self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                    self::printInfo("Run 'call cfg --help' to see available commands.");
                    break;
            }
        }
    }

    private function initArg(): void
    {
//        $filePath = Kernel::$pathRoot . '/composer.json';
//        if (file_exists($filePath) && is_readable($filePath)) {
//            $projectData = json_decode(
//                file_get_contents($filePath) ?: '',
//                true
//            );
//
//            $extra = $projectData['extra'] ?? [];
//
//            $extra['project']['name'] = basename(Kernel::$pathRoot);
//            $extra['project']['version'] = $extra['project']['version'] ?? '1.0.0';
//            $extra['project']['description'] = $extra['project']['description'] ?? 'Winter framework based';
//
//            $projectData['extra'] = $extra;
//
//            $json = json_encode($projectData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
//            file_put_contents($filePath, $json . PHP_EOL);
//        }

        $this->envCreate();
        $this->keyGenerate();
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
        $envPath = Kernel::$pathEnv;
        if (!file_exists($envPath)) {
            self::printWarning('.env file not found. Run: call cfg env -i');
            return;
        }

        $newKey  = bin2hex(random_bytes(32));
        $content = file_get_contents($envPath);

        if (preg_match('/^WINTER_KEY=(.*)$/m', $content, $match)) {
            $oldKey  = $match[1];
            $content = preg_replace('/^WINTER_KEY=.*$/m', 'WINTER_KEY=' . $newKey, $content);
            self::printKeyValue('Old key', $oldKey ?: '(empty)', 10, 34, 90);
        } else {
            $content .= PHP_EOL . 'WINTER_KEY=' . $newKey;
        }

        file_put_contents($envPath, $content);
        self::printSuccess('WINTER_KEY generated and saved to .env');
        self::printKeyValue('New key', $newKey, 10, 34, 32);
    }

    private function keyShow(): void
    {
        $key = env('WINTER_KEY', '');
        self::printLabel('WINTER_KEY', 34);
        if ($key) {
            self::printKeyValue('Key', $key, 6, 34, 36);
        } else {
            self::printWarning('WINTER_KEY is not set.');
            self::printInfo("Generate one with: call cfg key -g");
        }
        self::printLabel('WINTER_KEY', 34);
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
            if (copy($this->templatePath . '/Build/env', Kernel::$pathEnv)) {
                self::printBadge('.env', 'CREATED', 34, 32);
            } else {
                self::printBadge('.env', 'FAILED', 34, 31);
            }
        } else {
            self::printBadge('.env', 'EXISTS', 34, 33);
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
            if (is_file(Kernel::$pathEnv)) {
                self::printLabel(Kernel::$pathEnv, 34);
                self::printSplit(file_get_contents(Kernel::$pathEnv), 34);
                self::printLabel(Kernel::$pathEnv, 34);
            } else {
                self::printWarning("File '" . Kernel::$pathEnv . "' does not exist.");
                self::printInfo("Create it with: call cfg env -i");
            }
        } else {
            self::printLabel('ENVIRONMENT', 34);
            foreach ($_ENV as $key => $value) {
                self::printKeyValue($key, $value, 28, 34, 36);
            }
            self::printLabel('ENVIRONMENT', 34);
        }
    }

    private function dockerArg(): void
    {
        multiCopy($this->templatePath . '/Docker', Kernel::$pathRoot);
        self::printBadge('docker/', 'CREATED', 34, 32);
        self::printBadge('.dockerignore', 'CREATED', 34, 32);
        self::printBadge('docker-compose.yml', 'CREATED', 34, 32);
        self::printBadge('Dockerfile', 'CREATED', 34, 32);
    }

    private function openapiArg(): void
    {
        $filePath = Kernel::$pathMain . '/OpenApiController.php';
        if (!file_exists($filePath)) {
            $code = file_get_contents($this->templatePath . '/Packages/OpenApiTemplate');
            $fp   = fopen($filePath, "x");
            fwrite($fp, $code);
            fclose($fp);
            self::printBadge('OpenApiController', 'CREATED', 34, 32);
            self::printInfo("file://$filePath");
        } else {
            self::printBadge('OpenApiController', 'EXISTS', 34, 33);
        }
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Config Help", $cl);

        // usage
        self::printLabel("Usage", $cl);
        self::print("call cfg <command> -[flags] --[options]", $cl);
        self::printLabel("Usage", $cl);

        // commands overview
        self::printLabel("Commands", $cl);
        self::printBadge('init',    'initialize project: composer.json extras + .env', $cl, 36);
        self::printBadge('key',     'manage WINTER_KEY (project security key)',         $cl, 36);
        self::printBadge('env',     'manage .env environment file',                     $cl, 36);
        self::printBadge('docker',  'scaffold Docker configuration files',              $cl, 36);
        self::printBadge('openapi', 'create OpenAPI controller stub',                   $cl, 36);
        self::printLabel("Commands", $cl);

        // key
        self::printDivider($cl);
        self::printLabel("key — WINTER_KEY management", $cl);
        self::print("-g   generate (or regenerate) WINTER_KEY and save to .env", $cl);
        self::print("-s   show current WINTER_KEY value", $cl);
        self::printLabel("key — WINTER_KEY management", $cl);

        // env
        self::printLabel("env — environment file", $cl);
        self::print("-i           create .env from template (if not exists)", $cl);
        self::print("-s           show loaded environment variables", $cl);
        self::print("-s --file    show raw .env file contents", $cl);
        self::printLabel("env — environment file", $cl);

        // examples
        self::printDivider($cl);
        self::printLabel("Examples", $cl);
        self::printInfo("call cfg init");
        self::printInfo("call cfg key -g");
        self::printInfo("call cfg key -s");
        self::printInfo("call cfg env -i");
        self::printInfo("call cfg env -s");
        self::printInfo("call cfg env -s --file");
        self::printInfo("call cfg docker");
        self::printInfo("call cfg openapi");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/#");

        self::printTitle("Config Help", $cl);
    }
}
