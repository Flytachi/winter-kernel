<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Serve extends Cmd
{
    public static string $title = "start the PHP built-in development server";
    public final const string HOST = '0.0.0.0';
    public final const int PORT = 8000;

    public function handle(): void
    {
        self::printTitle("Serve", 34);
        $this->serveArg();
        self::printTitle("Serve", 34);
    }

    private function serveArg(): void
    {
        $isManual = isset($this->args['options']['host']) || isset($this->args['options']['port']);
        $host     = $this->args['options']['host'] ?? self::HOST;
        $port     = isset($this->args['options']['port']) ? (int) $this->args['options']['port'] : self::PORT;

        if ($isManual) {
            $connection = @fsockopen($host, $port);
            if (is_resource($connection)) {
                fclose($connection);
                self::printWarning("Address 'http://$host:$port' is already in use.");
                return;
            }
        } else {
            $basePort = $port;
            for ($i = 0; $i < 10; $i++) {
                $port       = $basePort + $i;
                $connection = @fsockopen($host, $port);
                if (is_resource($connection)) {
                    fclose($connection);
                    self::printWarning("Port $port is busy, trying next...");
                    if ($i === 9) {
                        self::printWarning("No free port found in range " . $basePort . "-" . ($basePort + 9) . ".");
                        return;
                    }
                } else {
                    break;
                }
            }
        }

        self::printSuccess("Server started at http://$host:$port");
        self::printKeyValue('Root', Kernel::$pathPublic, 6, 34, 90);
        passthru('php -S ' . escapeshellarg("$host:$port") . ' -t ' . escapeshellarg(Kernel::$pathPublic));
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Serve Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call serve --[options]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Options", $cl);
        self::print("--host   hostname  (default: " . self::HOST . ")", $cl);
        self::print("--port   port      (default: " . self::PORT . ")", $cl);
        self::print("", $cl);
        self::print("Without options the server auto-scans for a free port", $cl);
        self::print("starting from " . self::PORT . " up to " . (self::PORT + 9) . ".", $cl);
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call serve");
        self::printInfo("call serve --port=9000");
        self::printInfo("call serve --host=127.0.0.1 --port=8080");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/#");

        self::printTitle("Serve Help", $cl);
    }
}
