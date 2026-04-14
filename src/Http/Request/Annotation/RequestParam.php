<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Binds a controller method parameter to an HTTP query string parameter.
 *
 * If $name is omitted the PHP parameter name is used.
 * If the param is absent and the PHP param has a default value, the default is used.
 *
 * Example:
 *   public function index(
 *       #[RequestParam] int $page = 1,
 *       #[RequestParam('per_page')] int $perPage = 20,
 *   ): ResponseEntity { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestParam
{
    public function __construct(public ?string $name = null) {}
}
