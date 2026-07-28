<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Attribute;

/**
 * Enables the HTTP web tier — the analogue of Spring's `@EnableWebMvc`. Declared
 * on the {@see \Flytachi\Winter\K2\WinterApplication} class; produces one
 * {@see \Flytachi\Winter\K2\App\Component::http()} in the manifest.
 *
 * A pure capability toggle: it carries no host/port. The bind address is
 * deployment configuration, not a property of the application, so it lives in
 * `.env` / a {@see \Flytachi\Winter\K2\App\Config\WebConfigurer} and defaults to
 * `--host`/`--port` (fallback `0.0.0.0:8000`).
 *
 * ```
 * #[EnableWeb]
 * final class App extends WinterApplication { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableWeb
{
}
