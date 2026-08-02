<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures;

/**
 * Typed entity — buildSql() with this entity class will emit column list
 * derived from public properties (with alias prefix when set).
 */
final class UserEntity
{
    public int $id;
    public string $email;
    public ?string $name = null;
}
