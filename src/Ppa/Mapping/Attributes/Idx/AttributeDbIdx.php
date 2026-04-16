<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Index;

interface AttributeDbIdx extends AttributeDb
{
    public function columnPreparation(string $columnMain): void;
    public function toObject(string $dialect = 'mysql'): Index;
}
