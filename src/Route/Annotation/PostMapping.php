<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

/**
 * Maps a `POST` route onto the method. Repeatable — one method can answer several paths.
 *
 * @link https://winterframe.net/docs/routing POST route on a controller method
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class PostMapping extends AbstractMapping
{
    public function getMethod(): string
    {
        return 'POST';
    }
}
