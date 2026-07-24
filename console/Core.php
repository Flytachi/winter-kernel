<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console;

use Flytachi\Winter\Console\Inc\CoreHandle;

class Core extends CoreHandle
{
    /** Short aliases → Command class name */
    protected static array $aliases = [
        'sc' => 'Script',
        'th' => 'Thread',
        'proc' => 'Process',
        'dmn' => 'Daemon',
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
            ('Flytachi\Winter\Console\Command\\' . $cmd)::script(self::$arguments);
        } catch (\Throwable $exception) {
            self::printError($exception);
        }
    }
}
