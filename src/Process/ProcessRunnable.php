<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process;

use Flytachi\Winter\Thread\Runnable;

/**
 * Serializable entry point that runs a {@see Process} in a detached background
 * process.
 *
 * The launcher (via {@see \Flytachi\Winter\Thread\Thread}) re-execs the runner
 * binary, which boots the framework and then calls {@see run()}. Only the class
 * name travels across the process boundary — the process itself is rebuilt from
 * the container in the child, so nothing non-serializable is captured.
 */
final class ProcessRunnable implements Runnable
{
    /**
     * @param class-string<Process> $class
     */
    public function __construct(private string $class)
    {
    }

    /**
     * Runs in the detached child: starts the process in the foreground, so the
     * child becomes the process. {@inheritDoc}
     *
     * @param array<string, scalar> $args Launch arguments (unused — a process is self-configuring).
     */
    public function run(array $args): void
    {
        ($this->class)::start();
    }
}
