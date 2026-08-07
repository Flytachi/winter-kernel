<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DeleteMapping extends AbstractMapping
{
    public function getMethod(): string
    {
        return 'DELETE';
    }
}
