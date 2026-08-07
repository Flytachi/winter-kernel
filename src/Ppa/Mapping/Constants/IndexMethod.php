<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Constants;

enum IndexMethod: string
{
    case BTREE = 'BTREE';
    case HASH = 'HASH';
    case GIST = 'GIST';
    case GIN = 'GIN';
}
