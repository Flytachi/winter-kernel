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

        // WinterApplication owns `run`: it serves from its own run() before the
        // console dispatcher is ever reached, so this command handles only the legacy
        // Application path and will be removed together with it.
        $bootClass = BaseBoot::getBootClass();
        if ($bootClass === '' || !is_subclass_of($bootClass, Application::class)) {
            self::printWarning("`call run` needs a WinterApplication entry class.");
            self::printInfo("Extend WinterApplication and declare components with #[Enable*] attributes.");
            self::printInfo("Docs: doc-new/winter-application.md");
            return;
        }

        self::printWarning("Legacy Application path (deprecated) — prefer WinterApplication + #[Enable*].");
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
        self::print("Everything declared on your WinterApplication via #[Enable*]:", $cl);
        self::print("  #[EnableWeb]        -> the Swoole HTTP server (main)", $cl);
        self::print("  #[EnableProcess()]  -> a managed Process, attached via addProcess", $cl);
        self::print("  #[EnableDaemon()]   -> a supervised Daemon fleet", $cl);
        self::print("  #[EnableScheduler]  -> the #[Scheduled] scheduler", $cl);
        self::print("With no #[EnableWeb] the app runs headless (background only).", $cl);
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
