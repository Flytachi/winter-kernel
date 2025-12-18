<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

abstract class CmdCustom extends Printer implements CmdCustomInterface
{
    protected array $args;

    final public function __construct(array $args)
    {
        $this->args = $args;
        try {
            $this->init();
            $this->handle();
        } catch (\Throwable $exception) {
            self::printTitle(static::class, 31);
            self::printError($exception);
        }
    }

    final public static function script(array $args): void
    {
        new static($args);
    }

    protected function init(): void
    {
    }
}
