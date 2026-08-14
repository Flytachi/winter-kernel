<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

/**
 * Enables #[Async] proxying — the analogue of Spring's `@EnableAsync`. Declared on
 * the {@see \Flytachi\Winter\Kernel\WinterApplication} class.
 *
 * Without it the async collector is never wired: classes carrying #[Async] are not
 * proxied and their methods run synchronously (exactly like Spring without
 * `@EnableAsync`). With it, the proxies are generated during the boot scan.
 *
 * This is the one #[Enable*] attribute that changes the boot sequence rather than
 * contributing a {@see \Flytachi\Winter\Kernel\App\Component}.
 *
 * ```
 * #[EnableAsync]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/async Enabling #[Async] proxying
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableAsync
{
}
