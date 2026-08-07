<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Structure;

final class NameValidator
{
    public static function validate(string $name, int $maxLength = 63): void
    {
        if (empty($name)) {
            throw new \InvalidArgumentException("Name cannot be empty.");
        }

        // Basic validation: starts with a letter or underscore, contains only letters, numbers, underscores
        if (!preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*$/", $name)) {
            throw new \InvalidArgumentException(
                "Invalid name format: '{$name}'. Names must start with a letter or "
                . "underscore and contain only letters, numbers, and underscores."
            );
        }

        if (strlen($name) > $maxLength) {
            throw new \InvalidArgumentException("Name '{$name}' exceeds maximum length of {$maxLength} characters.");
        }
    }
}
