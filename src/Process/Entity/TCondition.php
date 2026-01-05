<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Entity;

enum TCondition: int
{
    case STARTED = 0;
    case ACTIVE = 1;
    case PREPARATION = 2;
    case WAITING = 3;
    case CHECKING = 4;
    case PASSIVE = 5;
}
