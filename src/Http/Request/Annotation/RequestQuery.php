<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Binds multiple query string parameters into a typed RequestObject subclass.
 *
 * Use when you need a DTO for several query params instead of binding each
 * individually with #[RequestParam].
 *
 * Example:
 *   public function index(#[RequestQuery] UserFilterRequest $filter): ResponseEntity { ... }
 *
 *   class UserFilterRequest extends RequestObject {
 *       public function __construct(
 *           public readonly int    $page    = 1,
 *           public readonly int    $perPage = 20,
 *           public readonly string $sort    = 'id',
 *       ) {}
 *   }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestQuery
{
}
