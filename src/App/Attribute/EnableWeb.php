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
 * It binds `0.0.0.0:8000`, changed at launch with `--http.port` / `--http.host`; the
 * address in code is a parameter of #[EnableHttp].
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
