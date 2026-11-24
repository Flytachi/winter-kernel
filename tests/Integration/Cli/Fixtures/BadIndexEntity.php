<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures;

use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Varchar;

/**
 * An entity with a plain B-tree index — nothing a check before running could refuse. The test
 * makes the server reject it: the table is there already, without the `code` column, so the
 * CREATE INDEX fails with error 1072 under the generic SQLSTATE 42000 — the class `db migrate`
 * used to read as "already exists" for indexes.
 */
#[Table]
final class BadIndexEntity
{
    #[Id]
    public int $id;

    #[Varchar(40)]
    #[Index]
    public string $code;
}
