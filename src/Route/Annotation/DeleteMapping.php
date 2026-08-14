<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

/**
 * Maps a `DELETE` route onto the method. Repeatable — one method can answer several paths.
 *
 * @link https://winterframe.net/docs/routing DELETE route on a controller method
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DeleteMapping extends AbstractMapping
{
    public function getMethod(): string
    {
        return 'DELETE';
    }
}
