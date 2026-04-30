<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

use Flytachi\Winter\DI\Container;

abstract class Cmd extends Printer implements CmdInterface
{
    public static string $title = "extra command title";
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
            $instance->isHelp();
            $instance->handle();
        } catch (\Throwable $exception) {
            self::printError($exception);
        }
    }

    protected function init(): void
    {
    }

    private function isHelp(): void
    {
        if (
            array_key_exists('help', $this->args['options'])
            || in_array('h', $this->args['flags'])
        ) {
            $this->help();
            die();
        }
    }
}
