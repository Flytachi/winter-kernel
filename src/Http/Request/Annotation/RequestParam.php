<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

use Attribute;

/**
 * Binds a controller method parameter to an HTTP query string parameter.
 *
 * If $name is omitted the PHP parameter name is used.
 * If the param is absent and the PHP param has a default value, the default is used.
 *
 * Example:
 * ```
 *   public function index(
 *       #[RequestParam] int $page = 1,
 *       #[RequestParam('per_page')] int $perPage = 20,
 *   ): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestParam
{
    /**
     * @param string|null $name Exact query string key (e.g. 'page_size' for ?page_size=25).
     *                          Omit to use the PHP parameter name with automatic normalization:
     *                          camelCase ($pageSize) matches ?pageSize=, ?page_size=, and ?page-size=.
     *                          An explicit name disables normalization — only exact match is used.
     */
    public function __construct(public ?string $name = null)
    {
    }
}
