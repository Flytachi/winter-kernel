<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;

final class Run extends Cmd
{
    public static string $title = "run the application (= application start)";

    public function handle(): void
    {
        self::printTitle("Run", 34);

        // `run` is owned by the application entry: WinterApplication::run() serves the
        // app from its own process before the console dispatcher is ever reached.
        // Reaching this command means the entry did not intercept `run`.
        self::printWarning("`call run` is served by your WinterApplication entry, not this command.");
        self::printInfo("Ensure your `call` launcher calls App::main(\$argv), where App extends WinterApplication.");
        self::printInfo("Declare components with #[EnableHttp] / #[EnableProcess] / #[EnableDaemon] / #[EnableScheduler] / #[EnableWebSocket].");
        self::printInfo("Docs: https://winterframe.net/docs/cmd-run");

        self::printTitle("Run", 34);
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Run Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call run       - run the application (production; DevWatcher off)", $cl);
        self::print("call run dev   - run the application (development; DevWatcher: memory + hot-reload)", $cl);
        self::print("`call run` is short for `call application start` — see `call help application`", $cl);
        self::printLabel("Usage", $cl);

        self::printDivider($cl);

        self::printLabel("What runs", $cl);
        self::print("This process is the application (PID 1 in a container): it starts every", $cl);
        self::print("component declared on your WinterApplication and supervises it:", $cl);
        self::print("  #[EnableHttp]        -> the Swoole HTTP server", $cl);
        self::print("  #[EnableWebSocket()] -> a WebSocket server", $cl);
        self::print("  #[EnableProcess()]   -> a Process", $cl);
        self::print("  #[EnableDaemon()]    -> a Daemon fleet", $cl);
        self::print("  #[EnableScheduler]   -> the #[Scheduled] scheduler", $cl);
        self::print("  components()         -> the same in code, plus scripts and outside programs", $cl);
        self::print("With no #[EnableHttp] the app runs headless (background only).", $cl);
        self::print("What starts: `call application info`; how it runs: `call application status`.", $cl);
        self::printLabel("What runs", $cl);

        self::printDivider($cl);

        self::printLabel("Options", $cl);
        self::print("-w / --watcher   force the DevWatcher on (same as `run dev`)", $cl);
        // Both are accepted by the server and both change where it can be reached, so
        // leaving them out of the help meant reading the documentation to learn that
        // `call run` can bind anywhere but 0.0.0.0:8000.
        self::print("--host=<addr>    bind address (default 0.0.0.0)", $cl);
        self::print("--port=<n>       port (default 8000)", $cl);
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call run");
        self::printInfo("call run dev");
        self::printInfo("call run --port=8080");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-run");

        self::printTitle("Run Help", $cl);
    }
}
