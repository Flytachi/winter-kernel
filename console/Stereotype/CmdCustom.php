<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Stereotype;

use Flytachi\Winter\Console\Inc\Printer;
use Flytachi\Winter\DI\Container;

/**
 * Minimal base for a one-off console script.
 *
 * Same discovery and the same `call script <dot.Path.Class>` entry point as
 * {@see \Flytachi\Winter\Console\Inc\Cmd}, without the obligations: no `\$title`,
 * no `help()`, no automatic `-h` handling. Use it for scripts nobody needs to
 * discover from a list; use `Cmd` for a command people will look up.
 *
 * @link https://winterframe.net/docs/cmd-script Cmd or CmdCustom, and how scripts are run
 */
abstract class CmdCustom extends Printer implements CmdCustomInterface
{
    protected array $args;

    final public function __construct(array $args)
    {
        $this->args = $args;
    }

    final public static function script(array $args): void
    {
        $instance = Container::getInstance()->make(static::class, ['args' => $args]);
        try {
            $instance->init();
            $instance->handle();
        } catch (\Throwable $exception) {
            self::printTitle(static::class, 31);
            self::printError($exception);
        }
    }

    protected function init(): void
    {
    }
}
