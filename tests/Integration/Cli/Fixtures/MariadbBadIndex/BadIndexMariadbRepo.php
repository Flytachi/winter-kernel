<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\MariadbBadIndex;

use Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\BadIndexEntity;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MariadbTestDbConfig;
use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class BadIndexMariadbRepo extends RepositoryView
{
    protected string $dbConfigClassName = MariadbTestDbConfig::class;
    protected string $entityClassName = BadIndexEntity::class;
    public static string $table = 'migr_badix';
}
