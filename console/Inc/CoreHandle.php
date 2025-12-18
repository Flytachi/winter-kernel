<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

abstract class CoreHandle extends Printer
{
    public static array $arguments = [
        'arguments' => [],
        'options' => [],
        'flags' => []
    ];

    final protected function parser($args): void
    {
        array_shift($args);

        while ($arg = array_shift($args)) {
            // Is it a command? (prefixed with --)
            if (str_starts_with($arg, '--')) {
                // is it the end of options flags
                if (!isset($arg[3])) {
                    $stat = true;
                    ; // end of options;
                    continue;
                }

                $value = "";
                $com   = substr($arg, 2);

                // is it the syntax '--option=argument'?
                if (strpos($com, '=')) {
                    list($com,$value) = explode("=", $com, 2);
                }

                static::$arguments['options'][$com] = !empty($value) ? $value : true;
                continue;
            }

            // Is it a flag or a serial of flags? (prefixed with -)
            if (str_starts_with($arg, '-')) {
                for ($i = 1; isset($arg[$i]); $i++) {
                    static::$arguments['flags'][] = $arg[$i];
                }
                continue;
            }

            // finally, it is not option, nor flag, nor argument
            static::$arguments['arguments'][] = $arg;
        }
    }
}
