<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Stereotype;

use Flytachi\Winter\Console\Inc\Printer;
use Flytachi\Winter\DI\Container;

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
