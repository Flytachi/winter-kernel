<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

use Attribute;

/**
 * Binds the entire query string to an array or a hydrated DTO.
 *
 * Use when multiple query parameters form a logical group (filters, pagination, search).
 * Always optional — missing or empty query string returns empty array / default-filled DTO.
 *
 * Supported types: array | stdClass | any class with a constructor.
 *
 * Query string values are cast to constructor parameter types (same logic as #[RequestParam]).
 * Scalar type casting: int, float, bool, string, BackedEnum,
 * DateTimeImmutable, DateTime, BcMath\Number, Decimal\Decimal.
 *
 * Add #[Valid] to trigger #[Constraint] validation on DTO fields after hydration.
 *
 * Example:
 * ```
 *   class OrderFilter {
 *       public function __construct(
 *           public readonly int     $page   = 1,
 *           public readonly int     $limit  = 20,
 *           public readonly ?string $search = null,
 *       ) {}
 *   }
 *
 *   public function list(#[RequestQuery] OrderFilter $filter): ResponseEntity { ... }
 *   public function validated(#[RequestQuery, Valid] OrderFilter $filter): ResponseEntity { ... }
 * ```
 *
 * @link https://winterframe.net/docs/requests Binding the query string as an object
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestQuery
{
}
