<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Declares a supervised {@see Daemon} fleet as part of the application — produces
 * one {@see \Flytachi\Winter\Kernel\App\Component::daemon()} in the manifest. Declared
 * on the {@see \Flytachi\Winter\Kernel\WinterApplication} class; repeatable.
 *
 * ```
 * #[EnableDaemon(Emails::class)]
 * #[EnableDaemon(Webhooks::class)]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/components Attaching a supervised daemon fleet
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
