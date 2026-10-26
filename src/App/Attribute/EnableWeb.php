<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

/**
 * The former name of {@see EnableHttp}, and the same: one
 * {@see \Flytachi\Winter\Kernel\App\Component::http()} in the manifest, with the
 * default supervision. Declare #[EnableHttp] instead — it also takes the supervision
 * parameters.
 *
 * @deprecated Use {@see EnableHttp}.
 *
 * A pure capability toggle: it carries no host/port. The bind address is
 * deployment configuration, not a property of the application, so it lives in
 * `.env` / a {@see \Flytachi\Winter\Kernel\App\Config\WebConfigurer} and defaults to
 * `--host`/`--port` (fallback `0.0.0.0:8000`).
 *
 * ```
 * #[EnableWeb]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/components What the web component runs, and headless mode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableWeb
{
}
