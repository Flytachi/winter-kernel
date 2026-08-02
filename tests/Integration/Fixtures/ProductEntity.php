<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Fixtures;

/**
 * Test entity used by all CRUD integration tests. Three primitive types
 * (int, string, ?float) cover the common shapes: PK, NOT-NULL varchar,
 * nullable numeric.
 */
final class ProductEntity
{
    public int $id;
    public string $name;
    public ?float $price = null;
}
