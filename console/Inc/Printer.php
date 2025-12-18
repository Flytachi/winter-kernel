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
}
