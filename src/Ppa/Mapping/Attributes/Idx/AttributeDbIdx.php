<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Idx;

use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Index;

interface AttributeDbIdx extends AttributeDb
{
    public function columnPreparation(string $columnMain): void;
    public function toObject(string $dialect = 'mysql'): Index;
}
