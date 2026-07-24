<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Daemon;

/**
 * When a supervised worker should be restarted after it exits.
 *
 * The naming follows the common convention (Kubernetes / systemd): a clean exit
 * means "the work is done", a non-zero exit or crash means "failure". Carried
 * inside a {@see RestartPolicy} together with the restart limit and back-off.
 */
enum RestartMode
{
    /** Restart on any exit — the worker must stay alive until stopped. */
    case ALWAYS;
    /** Restart only on a non-zero exit or crash; a clean exit is final. */
    case ON_FAILURE;
    /** Never restart — one attempt, then done. */
    case NEVER;

    /**
     * @param bool $crashed Whether the worker exited abnormally.
     */
    public function shouldRestart(bool $crashed): bool
    {
        return match ($this) {
            self::ALWAYS => true,
            self::ON_FAILURE => $crashed,
            self::NEVER => false,
        };
    }
}
