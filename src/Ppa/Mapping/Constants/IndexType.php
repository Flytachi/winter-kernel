<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Constants;

enum IndexType: string
{
    case PRIMARY = 'PRIMARY';
    case INDEX = 'INDEX';
    case UNIQUE = 'UNIQUE';
}
