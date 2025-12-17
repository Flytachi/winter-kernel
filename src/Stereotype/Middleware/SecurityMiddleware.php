<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype\Middleware;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Kernel\Factory\Middleware\AbstractMiddleware;
use Flytachi\Winter\Kernel\Factory\Middleware\MiddlewareException;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class SecurityMiddleware extends AbstractMiddleware
{
    public function optionBefore(): void
    {
        if (Header::getHeader('Authorization') == '') {
            throw new MiddlewareException('Authorization is empty');
        }
    }
}
