<?php

namespace Flytachi\Winter\Kernel;

use Flytachi\Winter\Base\Interface\ActuatorItemInterface;

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
