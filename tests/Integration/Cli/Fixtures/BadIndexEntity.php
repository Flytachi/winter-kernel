<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures;

use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\Ppa\Mapping\Constants\IndexMethod;

/**
 * An entity whose index MySQL and MariaDB reject: `USING GIN` is PostgreSQL syntax, and the
 * server answers with error 1064 under the generic SQLSTATE 42000 — the class `db migrate`
 * used to read as "already exists" for indexes.
 */
#[Table]
final class BadIndexEntity
{
    #[Id]
    public int $id;

    #[Varchar(40)]
    #[Index(method: IndexMethod::GIN)]
    public string $code;
}
