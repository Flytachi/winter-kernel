<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route\Annotation;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class PostMapping extends AbstractMapping
{
    public function getMethod(): string
    {
        return 'POST';
    }
}
