<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Stereotype;

use Flytachi\Winter\Base\Interface\Stereotype;

abstract class Controller extends Stereotype implements ControllerInterface
{
    final public function __construct()
    {
        parent::__construct();
    }
}
