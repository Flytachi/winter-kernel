<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

/**
 * Class-level route prefix or a method-level route with no specific HTTP method.
 *
 * @link https://winterframe.net/docs/routing Class prefix and multiple paths on one method
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequestMapping extends AbstractMapping
{
}
