<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

use Flytachi\Winter\DI\Container;

/**
 * Base class for a console command of your own.
 *
 * Discovered by the project scan and run through `call script <dot.Path.Class>`
 * (short: `call sc`). The container builds it, so `#[Autowired]` properties and
 * constructor dependencies work as anywhere else.
 *
 * Implement {@see handle()} for the body and {@see help()} for the usage text —
 * `-h` / `--help` prints it automatically. {@see \$title} is the one-line
 * description shown by `call script list`.
 *
 * @link https://winterframe.net/docs/cmd-script Writing your own command
 */
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
