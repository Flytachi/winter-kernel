<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\K2\Ppa\Entity\EntityInterface;

/**
 * Entity that overrides one column via the EntityInterface::selection() map.
 * Used to verify prepareSelect() consults the map for matched property names.
 */
final class UserWithSelectionEntity implements EntityInterface
{
    public int $id;
    public string $email;
    public string $fullName;

    public static function selection(): array
    {
        return [
            'fullName' => "CONCAT(first_name, ' ', last_name) AS fullName",
        ];
    }
}
