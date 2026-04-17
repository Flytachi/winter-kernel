<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype\Middleware;

use Attribute;
use Flytachi\Winter\Kernel\Factory\Middleware\Cors\AccessControlMiddleware;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class CorsMiddleware extends AccessControlMiddleware
{
}
