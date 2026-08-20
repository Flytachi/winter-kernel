<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

/**
 * Imports a Composer package as a route-prefixed sub-application — the analogue
 * of Spring's @Import. Declared on the {@see \Flytachi\Winter\Kernel\WinterApplication}
 * class; repeatable.
 *
 * The package's install path is resolved via Composer and its `src/` is scanned
 * for controllers automatically (no extra wiring). Replaces the old `plugins()`
 * hook.
 *
 * ```
 * #[Import('acme/auth-plugin', '/auth')]   // scanned, controllers mount under /auth
 * #[Import('acme/toolkit')]                // scanned, mounts no routes
 * #[Import('acme/billing', '/billing', required: false)]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/packages Packages
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Import
{
    /**
     * @param string $package Composer package name (e.g. 'acme/billing').
     * @param string|null $prefix URL prefix the package's controllers mount under
     *                            (e.g. '/billing'). Null scans the package without
     *                            mounting any of its routes — what a package of
     *                            services, commands or entities wants, and what spares
     *                            it inventing a URL it has no use for.
     * @param bool $required Throw if the package is not installed (default: true).
     */
    public function __construct(
        public string $package,
        public ?string $prefix = null,
        public bool $required = true,
    ) {
    }
}
