<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console;

use Flytachi\Winter\Console\Inc\CoreHandle;

final class Core extends CoreHandle
{
    /**
     * Short aliases → Command class name.
     *
     * One entry, and deliberately. An alias has to be guessable to save anything, and
     * `proc` / `dmn` / `sch` were not: they cost a lookup in the documentation to save
     * three keystrokes, while `call process` reads the same as what it does. `sc` stays
     * because `script` is the one name typed several times a session — it prefixes every
     * custom command an application has.
     */
    protected static array $aliases = [
        'sc' => 'Script',
    ];

    public function __construct($args)
    {
        $this->parser($args);
    }

    public static function getAliases(): array
    {
        return static::$aliases;
    }

    public function run(): void
    {
        try {
            $input = self::$arguments['arguments'][0] ?? null;
            $cmd   = $input !== null
                ? (static::$aliases[$input] ?? ucwords($input))
                : 'Help';
            $class = 'Flytachi\Winter\Console\Command\\' . $cmd;

            // A name that resolves to no command is the operator's typo, a removed alias
            // still in muscle memory, or a command they expected to exist. Letting the
            // autoloader answer turned all three into `Class "…\Command\Proc" not found`
            // plus a stack trace through the kernel — an internal detail presented as if
            // the framework itself had broken.
            if (!class_exists($class)) {
                self::printWarning("Unknown command '{$input}'.");
                self::printInfo("Run 'call' to see the available commands.");
                return;
            }

            $class::script(self::$arguments);
        } catch (\Throwable $exception) {
            self::printError($exception);
        }
    }
}
