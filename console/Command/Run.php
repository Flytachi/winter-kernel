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
        // Named after the component: with an HTTP server and a WebSocket, a bare --port
        // would name neither — so it is refused, and so is a name nobody declared.
        self::print("--http.port=<n>      the HTTP server's port (declared: #[EnableHttp(port:)], default 8000)", $cl);
        self::print("--http.host=<addr>   its bind address (default 0.0.0.0)", $cl);
        self::print("--<name>.port=<n>    a WebSocket's port, by the name `call application info` shows", $cl);
        self::print("--<name>.host=<addr> a WebSocket's bind address", $cl);
        self::print("A ServerConfigurer that sets the address in code overrides --http.*.", $cl);
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call run");
        self::printInfo("call run dev");
        self::printInfo("call run --http.port=8080");
        self::printInfo("call run --http.port=8080 --chat.port=8081");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-run");

        self::printTitle("Run Help", $cl);
    }
}
