<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Factory\Mapping as MappingFactory;
use Flytachi\Winter\Kernel\Factory\Plugin;
use Flytachi\Winter\Kernel\Http\PluginRouter;
use Flytachi\Winter\Kernel\Http\Router;

class Mapping extends Cmd
{
    public static string $title = "manage route mapping cache (build, clean, show)";

    public function handle(): void
    {
        self::printTitle("Mapping", 34);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Mapping", 34);
    }

    private function resolution(): void
    {
        switch ($this->args['arguments'][1] ?? '') {
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
                self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                self::printInfo("Run 'call mapping --help' to see available commands.");
                break;
        }
    }

    private function showArg(): void
    {
        try {
            // App routes
            $declaration = MappingFactory::scanningDeclaration();
            $children    = $declaration->getChildren();

            self::printLabel("App Routes", 34);
            if (empty($children)) {
                self::printInfo("No routes registered.");
            } else {
                foreach ($children as $item) {
                    $key   = str_pad($item->getMethod() ?: '*', 7) . ' /' . ltrim($item->getUrl(), '/');
                    $value = '→ ' . $item->getClassName() . '->' . $item->getClassMethod() . '()';
                    self::printKeyValue($key, $value, 45, 34, 36);
                }
            }
            self::printLabel("App Routes", 34);

            // Plugin routes
            $plugins = Plugin::getPlugins();
            foreach ($plugins as $pluginPrefix => $pluginPath) {
                $pluginDeclaration = MappingFactory::scanningDeclaration($pluginPath);
                $pluginChildren    = $pluginDeclaration->getChildren();

                self::printLabel("Plugin [$pluginPrefix]", 36);
                if (empty($pluginChildren)) {
                    self::printInfo("No routes registered.");
                } else {
                    foreach ($pluginChildren as $item) {
                        $key   = str_pad($item->getMethod() ?: '*', 7) . " /{$pluginPrefix}/" . ltrim($item->getUrl(), '/');
                        $value = '→ ' . $item->getClassName() . '->' . $item->getClassMethod() . '()';
                        self::printKeyValue($key, $value, 45, 36, 37);
                    }
                }
                self::printLabel("Plugin [$pluginPrefix]", 36);
            }
        } catch (\Throwable $e) {
            self::printWarning("Show failed: " . $e->getMessage());
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
            $router = new Router();
            $router->generateMappingRoutes();
            self::printBadge("App", 'BUILT', 34, 32);

            $plugins = Plugin::getPlugins();
            if (!empty($plugins)) {
                $pluginRouter = new PluginRouter();
                foreach ($plugins as $pluginPrefix => $pluginPath) {
                    $pluginRouter->generateMappingRoutes($pluginPath, $pluginPrefix);
                    self::printBadge("Plugin [$pluginPrefix]", 'BUILT', 36, 32);
                }
            }
        } catch (\Throwable $e) {
            self::printWarning("Build failed: " . $e->getMessage());
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
            $router = new Router();
            if (file_exists($router->getPathMapping())) {
                unlink($router->getPathMapping());
                self::printBadge("App", 'CLEANED', 34, 32);
            } else {
                self::printBadge("App", 'SKIPPED', 34, 33);
            }

            $plugins = Plugin::getPlugins();
            if (!empty($plugins)) {
                $pluginRouter = new PluginRouter();
                foreach ($plugins as $pluginPrefix => $pluginPath) {
                    $pluginMappingFile = $pluginRouter->getFolderMapping() . $pluginPrefix . '.php';
                    if (file_exists($pluginMappingFile)) {
                        unlink($pluginMappingFile);
                        self::printBadge("Plugin [$pluginPrefix]", 'CLEANED', 36, 32);
                    } else {
                        self::printBadge("Plugin [$pluginPrefix]", 'SKIPPED', 36, 33);
                    }
                }
            }
        } catch (\Throwable $e) {
            self::printWarning("Clean failed: " . $e->getMessage());
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

        self::printLabel("Usage", $cl);
        self::print("call mapping [command]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('show',  'display all registered routes (app + plugins)', $cl, 36);
        self::printBadge('build', 'generate and cache route mapping files',        $cl, 36);
        self::printBadge('clean', 'delete cached route mapping files',             $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call mapping show");
        self::printInfo("call mapping build");
        self::printInfo("call mapping clean");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/#");

        self::printTitle("Mapping Help", $cl);
    }
}