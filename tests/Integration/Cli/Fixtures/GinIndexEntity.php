<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures;

use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\Ppa\Mapping\Constants\IndexMethod;

/** An index MySQL and MariaDB have no kind of: `USING GIN` is PostgreSQL's. */
#[Table]
final class GinIndexEntity
{
    #[Id]
    public int $id;

    #[Varchar(40)]
    #[Index(method: IndexMethod::GIN)]
    public string $code;
}
