<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Attribute;

/**
 * Imports a Composer package as a route-prefixed sub-application — the analogue
 * of Spring's @Import. Declared on the {@see \Flytachi\Winter\K2\WinterApplication}
 * class; repeatable.
 *
 * The package's install path is resolved via Composer and its `src/` is scanned
 * for controllers automatically (no extra wiring). Replaces the old `plugins()`
 * hook.
 *
 * ```
 * #[Import('acme/auth-plugin', '/auth')]
 * #[Import('acme/billing', '/billing', required: false)]
 * final class App extends WinterApplication { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Import
{
    /**
     * @param string $package Composer package name (e.g. 'acme/billing').
     * @param string $prefix URL prefix the package mounts under (e.g. '/billing').
     * @param bool $required Throw if the package is not installed (default: true).
     */
    public function __construct(
        public string $package,
        public string $prefix,
        public bool $required = true,
    ) {
    }
}
