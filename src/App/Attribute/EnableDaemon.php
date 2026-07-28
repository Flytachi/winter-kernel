<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Attribute;

use Flytachi\Winter\K2\Process\Daemon\Daemon;

/**
 * Declares a supervised {@see Daemon} fleet as part of the application — produces
 * one {@see \Flytachi\Winter\K2\App\Component::daemon()} in the manifest. Declared
 * on the {@see \Flytachi\Winter\K2\WinterApplication} class; repeatable.
 *
 * ```
 * #[EnableDaemon(Emails::class)]
 * #[EnableDaemon(Webhooks::class)]
 * final class App extends WinterApplication { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class EnableDaemon
{
    /**
     * @param class-string<Daemon> $class The Daemon class to supervise.
     */
    public function __construct(public string $class)
    {
    }
}
