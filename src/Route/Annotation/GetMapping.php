<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

/**
 * Maps a `GET` route onto the method. Repeatable — one method can answer several paths.
 *
 * @link https://winterframe.net/docs/routing GET route on a controller method
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class GetMapping extends AbstractMapping
{
    public function getMethod(): string
    {
        return 'GET';
    }
}
