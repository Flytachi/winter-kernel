<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

/** Class-level route prefix or a method-level route with no specific HTTP method. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequestMapping extends AbstractMapping
{
}
