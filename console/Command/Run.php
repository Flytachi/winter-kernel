<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;

final class Run extends Cmd
{
    public static string $title = "run the application: web + declared components (Swoole)";

    public function handle(): void
    {
        self::printTitle("Run", 34);

        // `run` is owned by the application entry: WinterApplication::run() serves the
        // app from its own process before the console dispatcher is ever reached.
        // Reaching this command means the entry did not intercept `run`.
        self::printWarning("`call run` is served by your WinterApplication entry, not this command.");
        self::printInfo("Ensure your `call` launcher calls App::main(\$argv), where App extends WinterApplication.");
        self::printInfo("Declare components with #[EnableWeb] / #[EnableProcess] / #[EnableDaemon] / #[EnableScheduler].");
        self::printInfo("Docs: doc-new/winter-application.md");

        self::printTitle("Run", 34);
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
        self::printInfo("Docs: https://winterframe.net/docs/cmd-run");

        self::printTitle("Run Help", $cl);
    }
}
