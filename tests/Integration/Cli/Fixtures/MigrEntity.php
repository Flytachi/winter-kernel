<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal\Boolean;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal\Varchar;

/**
 * Shared entity for Phase C.5 (Cli `db migrate` E2E tests).
 *
 * The attribute mix exercises a representative subset of the Mapping layer
 * so a successful `db migrate` validates the whole pipeline end-to-end:
 *
 *   - `#[Table]`              — entity opted into migration
 *   - `#[Id]`                 — hybrid → PK + auto-increment + NOT NULL + INT
 *   - `#[Varchar(100)]` + `#[Unique]` — VARCHAR column with a UNIQUE index
 *   - `#[Boolean]` + `#[NullableIs(false)]` — BOOLEAN NOT NULL
 *
 * Driver-specific Repo classes (under Pg/, Mysql/, Mariadb/) all reference
 * this same entity so the schema declaration is identical across drivers.
 */
#[Table]
final class MigrEntity
{
    #[Id]
    public int $id;

    #[Varchar(100)]
    #[NullableIs(false)]
    #[Unique]
    public string $name;

    #[Boolean]
    #[NullableIs(false)]
    public bool $active;
}
