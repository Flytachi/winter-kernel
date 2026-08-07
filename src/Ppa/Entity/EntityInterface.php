<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Entity;

/**
 * Marker interface for entity classes with custom column selection mapping.
 *
 * Implement this interface to override which columns are emitted in `SELECT`
 * clauses when {@see RepositoryCore} builds SQL for this entity type.
 * Return a map of `[propertyName => 'sql_expression']` from {@see selection()};
 * any property not present in the map is selected by its plain name (with alias prefix).
 *
 * Example:
 * ```
 * class UserEntity implements EntityInterface
 * {
 *     public int    $id;
 *     public string $fullName;
 *
 *     public static function selection(): array
 *     {
 *         return [
 *             'fullName' => "CONCAT(first_name, ' ', last_name) AS fullName",
 *         ];
 *     }
 * }
 * // Produces: SELECT u.id, CONCAT(first_name, ' ', last_name) AS fullName FROM users u
 * ```
 */
interface EntityInterface
{
    /**
     * Returns a custom SQL expression map for entity properties.
     *
     * Keys are PHP property names; values are raw SQL column expressions
     * (including any alias). Properties absent from this map are selected
     * using the repository's alias prefix and the plain property name.
     *
     * @return array<string, string> Map of [propertyName => 'sql_expression']
     */
    public static function selection(): array;
}
