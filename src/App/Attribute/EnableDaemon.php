<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\App\Component;
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
     * A daemon is never restarted by the application: it restarts its own workers, and
     * when it fails itself — gives up, or is killed — it is down.
     *
     * @param class-string<Daemon> $class The Daemon class to supervise.
     * @param bool $critical Whether the application stops when it fails (the default);
     *                       false leaves it down, marked failed, while the rest runs on.
     * @param int $startTimeout Seconds for its workers to come up; 0 waits for good.
     */
    public function __construct(
        public string $class,
        public bool $critical = true,
        public int $startTimeout = Component::START_TIMEOUT,
    ) {
    }
}
