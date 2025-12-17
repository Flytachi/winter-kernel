<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype\Middleware;

use Attribute;
use Flytachi\Winter\Kernel\Factory\Middleware\Cors\AccessControlMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class CorsMiddleware extends AccessControlMiddleware
{
}
