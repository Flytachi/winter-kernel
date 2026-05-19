<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Fixtures;

use Flytachi\Winter\K2\Ppa\Stereotype\Repository;

final class SpecimenMariadbRepo extends Repository
{
    protected string $dbConfigClassName = MariadbTestDbConfig::class;
    protected string $entityClassName = SpecimenEntity::class;
    public static string $table = 'specimens';
}
