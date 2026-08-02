<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Declares a single managed {@see Process} worker as part of the application —
 * produces one {@see \Flytachi\Winter\Kernel\App\Component::process()} in the manifest.
 * Declared on the {@see \Flytachi\Winter\Kernel\WinterApplication} class; repeatable.
 *
 * ```
 * #[EnableProcess(SnmpProc::class)]
 * #[EnableProcess(HeartbeatProc::class)]
 * final class App extends WinterApplication { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class EnableProcess
{
    /**
     * @param class-string<Process> $class The Process class to run.
     */
    public function __construct(public string $class)
    {
    }
}
