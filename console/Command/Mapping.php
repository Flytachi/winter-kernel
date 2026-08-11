<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Route\Router;

final class Mapping extends Cmd
{
    public static string $title = "list the routes the application exposes";

    public function handle(): void
    {
        self::printTitle("Mapping", 34);

        $sub = $this->args['arguments'][1] ?? '';

        match ($sub) {
            'show'  => $this->showArg($this->args['arguments'][2] ?? ''),
            ''      => self::help(),
            default => $this->showArg($sub),
        };

        self::printTitle("Mapping", 34);
    }

    private function showArg(string $pattern): void
    {
        try {
            $routes  = Router::fromScan(Kernel::$pathRoot)->getRoutesSummary();
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
            self::printWarning("Show failed: " . $e->getMessage());
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
        self::print("call mapping [show] [pattern]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('show', 'list all registered routes (default)', $cl, 36);
        self::printBadge('show <pattern>', 'filter routes by URL fragment', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call mapping");
        self::printInfo("call mapping show");
        self::printInfo("call mapping show api/user");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/3.0.0/cmd-mapping");

        self::printTitle("Mapping Help", $cl);
    }
}
