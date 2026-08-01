<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Route\Fixtures\App;

use Flytachi\Winter\DI\Attribute\Singleton;

/** A dependency the controller must receive from the container, not construct itself. */
#[Singleton]
final class GreetingService
{
    public function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}
