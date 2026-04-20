<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route\Annotation;

use Attribute;

/** Class-level route prefix or a method-level route with no specific HTTP method. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequestMapping extends AbstractMapping
{
}
