<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Thrown from an interruptible blocking point — for example
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::sleep()} — when a stop
 * has been requested while the body was blocked.
 *
 * Mirrors Java's `InterruptedException`: a blocked body wakes immediately instead
 * of running the blocking call to completion. Leave it uncaught for a graceful
 * unwind (finally blocks still run); catch it to handle partial work — return a
 * job to the queue, roll back, log — before the process stops.
 */
final class InterruptedException extends \RuntimeException
{
    public function __construct(string $message = 'Process interrupted')
    {
        parent::__construct($message);
    }
}
