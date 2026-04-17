<?php

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
final class Actuator
{
    public static function use(ActuatorItemInterface ...$items): never
    {
        foreach ($items as $item) {
            $item->run();
        }
        exit;
    }
}
