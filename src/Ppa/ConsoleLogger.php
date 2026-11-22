<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa;

use Flytachi\Winter\Console\Inc\Printer;
use Psr\Log\AbstractLogger;

/**
 * What the schema versions say, for a person at a terminal: the console's lines instead of
 * log entries — `call db …`, and `call run` when its output is a terminal
 * ({@see PpaSchema::startLogger()}). Debug is left out.
 */
final class ConsoleLogger extends AbstractLogger
{
    private const array LOUD = ['warning', 'error', 'critical', 'alert', 'emergency'];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($level === 'debug') {
            return;
        }
        in_array($level, self::LOUD, true)
            ? Printer::printWarning((string) $message)
            : Printer::printInfo((string) $message);
    }
}
