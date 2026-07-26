<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Application;
use Flytachi\Winter\K2\BaseBoot;

class Run extends Cmd
{
    public static string $title = "run the application: web + declared components (Swoole)";

    public function handle(): void
    {
        self::printTitle("Run", 34);

        $sub   = $this->args['arguments'][1] ?? null;
        $watch = $sub === 'dev'
            || in_array('w', $this->args['flags'] ?? [], true)
            || isset($this->args['options']['watcher']);

        $bootClass = BaseBoot::getBootClass();
        if ($bootClass === '' || !is_subclass_of($bootClass, Application::class)) {
            self::printWarning("`call run` requires your Boot class to extend Application.");
            self::printInfo("Change `extends BaseBoot` to `extends Application` and declare components().");
            self::printInfo("Docs: docs/starter/00-quickstart.md");
            return;
        }

        self::printSuccess($watch ? "Starting application (dev / watch)" : "Starting application");

        // serve() blocks until shutdown and exits the process itself.
        $bootClass::serve($watch);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Run Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call run       - run the application (production; DevWatcher off)", $cl);
        self::print("call run dev   - run the application (development; DevWatcher: memory + hot-reload)", $cl);
        self::printLabel("Usage", $cl);

        self::printDivider($cl);

        self::printLabel("What runs", $cl);
        self::print("Everything declared in your App::components():", $cl);
        self::print("  Component::http()      -> the Swoole HTTP server (main)", $cl);
        self::print("  Component::process()   -> a managed Process, attached via addProcess", $cl);
        self::print("  Component::daemon()    -> a supervised Daemon fleet", $cl);
        self::print("  Component::scheduler() -> the #[Scheduled] scheduler", $cl);
        self::print("With no Component::http() the app runs headless (background only).", $cl);
        self::printLabel("What runs", $cl);

        self::printDivider($cl);

        self::printLabel("Options", $cl);
        self::print("-w / --watcher   force the DevWatcher on (same as `run dev`)", $cl);
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call run");
        self::printInfo("call run dev");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/2.0.0/cmd-run");

        self::printTitle("Run Help", $cl);
    }
}
