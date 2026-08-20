<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

abstract class Printer
{
    public static function printTitle(string $message, int $cl = 33): void
    {
        $pad  = max(2, (int)((72 - strlen($message)) / 2));
        $line = str_repeat('=', $pad);
        echo "\033[" . $cl . "m" . " | [{$line} {$message} {$line}]\n";
        echo "\033[0m";
    }

    public static function printError(\Throwable $exception): never
    {
        self::printTitle($exception::class, 31);
        self::printMessage($exception->getMessage(), 31);
        if (env('DEBUG', false)) {
            self::printSplit($exception->getTraceAsString(), 31);
        }
        self::printTitle($exception::class, 31);
        die();
    }

    public static function printLabel(string $message, int $cl = 33): void
    {
        echo "\033[" . $cl . "m" . " | [ $message ]\n";
        echo "\033[0m";
    }

    public static function print(string $message, int $cl = 33): void
    {
        echo "\033[" . $cl . "m" . " |\t $message \n";
        echo "\033[0m";
    }

    public static function printSplit(string $message = '', int $cl = 33): void
    {
        if ($message) {
            foreach (explode(PHP_EOL, $message) as $str) {
                echo "\033[" . $cl . "m" . " |\t $str \n";
            }
        } else {
            echo "\033[" . $cl . "m" . " |\t No data \n";
        }
        echo "\033[0m";
    }

    public static function printMessage(string $message, int $cl = 33): void
    {
        echo "\033[" . $cl . "m" . " | [→] $message \n";
        echo "\033[0m";
    }

    // --- Semantic shortcuts ---

    public static function printSuccess(string $message): void
    {
        echo "\033[32m" . " | [✓] $message \n";
        echo "\033[0m";
    }

    public static function printWarning(string $message): void
    {
        echo "\033[33m" . " | [!] $message \n";
        echo "\033[0m";
    }

    /**
     * A refusal that names what is missing and how to get it.
     *
     * {@see \Flytachi\Winter\Kernel\Core\DepSupport::demand()} throws a two-line
     * message: what needs the package, and the `composer require` that installs it.
     * Handing that whole string to {@see printError()} is a TypeError — it takes a
     * Throwable — so the instruction never reached the operator and a stack trace
     * arrived instead. Each line gets the marker that fits it: the problem as a
     * warning, the command to run as info.
     */
    public static function printMissingDependency(string $message): void
    {
        $lines = preg_split('/\R/', trim($message)) ?: [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $i === 0 ? self::printWarning($line) : self::printInfo($line);
        }
    }

    public static function printInfo(string $message): void
    {
        echo "\033[36m" . " | [i] $message \n";
        echo "\033[0m";
    }

    // --- Layout ---

    public static function printDivider(int $cl = 90): void
    {
        echo "\033[" . $cl . "m" . " | - - - - - - - - - - - - - - - - - - - - - - - \n";
        echo "\033[0m";
    }

    /**
     * Formatted key → value pair, key padded to $pad chars.
     */
    public static function printKeyValue(string $key, string $value, int $pad = 20, int $cl = 33, int $vcl = 36): void
    {
        $keyStr = str_pad($key, $pad);
        echo "\033[" . $cl . "m" . " |\t $keyStr ";
        echo "\033[" . $vcl . "m" . "$value \n";
        echo "\033[0m";
    }

    /**
     * Bulleted list of items.
     */
    public static function printList(array $items, int $cl = 33): void
    {
        foreach ($items as $item) {
            echo "\033[" . $cl . "m" . " |\t • $item \n";
        }
        echo "\033[0m";
    }

    // --- Progress / Steps ---

    /**
     * Step indicator: [current/total] message
     */
    public static function printStep(int $current, int $total, string $message, int $cl = 36): void
    {
        echo "\033[" . $cl . "m" . " | [$current/$total] $message \n";
        echo "\033[0m";
    }

    /**
     * Inline line with a right-aligned colored badge: message ........... [badge]
     */
    public static function printBadge(string $message, string $badge, int $cl = 33, int $bcl = 32): void
    {
        $line = str_pad(" |\t $message ", 65, '.');
        echo "\033[" . $cl . "m" . $line . " ";
        echo "\033[" . $bcl . "m" . "[$badge]";
        echo "\033[0m\n";
    }
}
