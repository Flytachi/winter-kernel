<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Route\Router;

class Mapping extends Cmd
{
    public static string $title = "search registered routes by URL pattern";

    public function handle(): void
    {
        self::printTitle("Mapping", 34);
        $this->searchArg($this->args['arguments'][1] ?? '');
        self::printTitle("Mapping", 34);
    }

    private function searchArg(string $pattern): void
    {
        try {
            $router = Router::fromScan(Kernel::$pathRoot);
            $routes = $router->getRoutesSummary();

            $pattern = trim($pattern, '/');
            $matched = $pattern === ''
                ? $routes
                : array_values(array_filter(
                    $routes,
                    fn($r) => str_contains(ltrim($r['path'], '/'), $pattern)
                ));

            $label = $pattern === '' ? 'Routes' : "Matched '$pattern'";
            if (empty($matched)) {
                self::printWarning("No routes matching '$pattern'.");
            } else {
                self::printLabel("$label (" . count($matched) . ")", 34);
                foreach ($matched as $route) {
                    $key = str_pad($route['method'], 7) . ' ' . $route['path'];
                    self::printKeyValue($key, '→ ' . $route['handler'], 45, 34, 36);
                }
                self::printLabel($label, 34);
            }
        } catch (\Throwable $e) {
            self::printWarning("Search failed: " . $e->getMessage());
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
        self::print("call mapping <url-pattern>", $cl);
        self::printLabel("Usage", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call mapping test/view");
        self::printInfo("call mapping api/user");
        self::printInfo("call mapping /health");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/3.0.0/cmd-mapping");

        self::printTitle("Mapping Help", $cl);
    }
}
