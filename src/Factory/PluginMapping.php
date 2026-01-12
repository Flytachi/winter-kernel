<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory;

use Attribute;
use Flytachi\Winter\Kernel\Factory\Middleware\MiddlewareInterface;
use Flytachi\Winter\Kernel\Stereotype\ControllerInterface;
use Flytachi\Winter\Mapping\Annotation\AbstractMapping;
use Flytachi\Winter\Mapping\MappingRequestInterface;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class PluginMapping
{
    /**
     * @param string $url
     * @param class-string<ControllerInterface> $controllerClassName
     * @param null|class-string<MiddlewareInterface> $middlewareClassName
     */
    public function __construct(
        public string $url,
        public string $controllerClassName,
        public ?string $middlewareClassName = null,
    ) {
    }
}
