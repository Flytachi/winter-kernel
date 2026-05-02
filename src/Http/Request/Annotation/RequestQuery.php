<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Binds the entire query string to an array or a plain DTO class.
 *
 * Use when multiple query parameters form a logical group (filters, pagination, search).
 * Always optional — missing query string returns empty array / default-filled DTO.
 *
 * Supported types: array | any class with a constructor.
 *
 * Example:
 *   class OrderFilter {
 *       public function __construct(
 *           public readonly int     $page   = 1,
 *           public readonly int     $limit  = 20,
 *           public readonly ?string $search = null,
 *       ) {}
 *   }
 *
 *   public function list(#[RequestQuery] OrderFilter $filter): ResponseEntity { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestQuery
{
}
