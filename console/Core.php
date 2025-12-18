<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console;

use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use Flytachi\Winter\Console\Inc\CoreHandle;

class Core extends CoreHandle implements ActuatorItemInterface
{
    public function __construct($args)
    {
        $this->parser($args);
    }

    public function run(): void
    {
        try {
            if (array_key_exists(0, self::$arguments['arguments'])) {
                $cmd = ucwords(self::$arguments['arguments'][0]);
            } else {
                $cmd = 'Help';
            }
            ('Flytachi\Winter\Console\Command\\' . $cmd)::script(self::$arguments);
        } catch (\Throwable $exception) {
            self::printError($exception);
        }
    }
}
