<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

/**
 * Applies the pending schema versions of every `#[Migratable]` database when the
 * application starts — after the High components (a database the application runs itself)
 * are up, before anything else starts: no request meets an old schema.
 *
 * ```
 * #[EnableHttp]
 * #[EnablePpaMigration]
 * final class Application extends WinterApplication { … }
 * ```
 *
 * It is what `call db migrate` does, in the place of onStarting(): a version that fails
 * stops the start — it says which and why, nothing else starts, the application exits
 * with 1. An application's own onStarting() runs after it. A database without a history
 * (`ppa/<Config>/`) is told of and left alone. What it did is said in the console's lines
 * when the output is a terminal, in log entries when it is not; nothing when there is
 * nothing to apply. `Application up` comes after it.
 *
 * Without it `call run dev` says that the versions are not applied at start.
 *
 * Needs flytachi/winter-ppa: without it the start is refused.
 *
 * @link https://winterframe.net/docs/migrations Schema versions at start
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnablePpaMigration
{
}
