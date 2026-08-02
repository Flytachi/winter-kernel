<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Fixtures;

use Flytachi\Winter\Kernel\Ppa\Stereotype\Repository;

final class SpecimenPgRepo extends Repository
{
    protected string $dbConfigClassName = PgTestDbConfig::class;
    protected string $entityClassName = SpecimenEntity::class;
    public static string $table = 'specimens';
}
