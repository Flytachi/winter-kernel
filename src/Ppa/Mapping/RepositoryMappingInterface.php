<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping;

interface RepositoryMappingInterface
{
    public function originTable(): string;
    public function mapIdentifierColumnName(): string;
}
