<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

abstract class Cmd extends Printer implements CmdInterface
{
    public static string $title = "extra command title";
    protected array $args;

    final public function __construct(array $args)
    {
        $this->args = $args;
        try {
            $this->init();
            $this->isHelp();
            $this->handle();
        } catch (\Throwable $exception) {
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
