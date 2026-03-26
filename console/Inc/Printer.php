<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

abstract class Printer
{
    public static function printStart(string $arg): void
    {
        echo "\033[32m" . " | ===> {$arg}\n";
        echo "\033[0m";
    }

    public static function printTitle(string $message, int $cl = 33): void
    {
        echo "\033[" . $cl . "m" . " | [====================== $message ======================]\n";
        echo "\033[0m";
    }

    public static function printError(\Throwable $exception): never
    {
        self::printTitle($exception->getMessage(), 31);
        self::printSplit($exception->getTraceAsString(), 31);
        self::printTitle($exception->getMessage(), 31);
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
            echo "\033[" . $cl . "m" . " |\t Нет данных \n";
        }
        echo "\033[0m";
    }

    public static function printMessage(string $message, int $cl = 33): void
    {
        echo "\033[" . $cl . "m" . " | ==> $message \n";
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

    public static function printInfo(string $message): void
    {
        echo "\033[36m" . " | [i] $message \n";
        echo "\033[0m";
    }

    // --- Layout ---

    public static function printDivider(int $cl = 90): void
    {
        echo "\033[" . $cl . "m" . " | - - - - - - - - - - - - - - - - - - - - \n";
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
        $line   = str_pad(" |\t $message ", 55, '.');
        echo "\033[" . $cl . "m" . $line . " ";
        echo "\033[" . $bcl . "m" . "[$badge]";
        echo "\033[0m\n";
    }
}
