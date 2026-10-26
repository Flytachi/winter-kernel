<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

/**
 * What a component tells the supervisor that started it — the child's end of a socket
 * pair the supervisor opened before the fork.
 *
 * Three facts, each of which the supervisor cannot see from outside:
 *
 * - **ready** — the component is up (a process entered its body, a WebSocket listens, a
 *   daemon's workers run). Before this a crash is a failure to start, after it a crash
 *   of a running component.
 * - **stopped** — the component ends because it was asked to (a stop signal), not
 *   because it broke: not a failure, and not restarted.
 * - **failed** — the component gave up by its own rules (a daemon whose workers keep
 *   crashing), even though it exits in an orderly way.
 * - **held** — the component could not start because a process outside the application
 *   runs it already: not broken, only taken.
 *
 * Outside a supervisor — `call process … start`, `dispatch()` — there is no channel and
 * every call is a no-op.
 *
 * @internal Between the framework's components and their supervisor.
 */
final class ComponentChannel
{
    public const string READY = 'R';
    public const string STOPPED = 'S';
    public const string FAILED = 'F';
    public const string HELD = 'H';

    /** @var resource|null */
    private static mixed $socket = null;
    private static bool $readySent = false;

    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Binds this process to its supervisor. Called in the child right after the fork.
     *
     * @param resource $socket
     */
    public static function attach(mixed $socket): void
    {
        self::$socket = $socket;
        self::$readySent = false;
    }

    /**
     * Leaves the channel to the parent: a process forked from a component — a daemon's
     * worker — speaks for itself, not for the component. The descriptor stays open for
     * the parent, whose channel it is.
     */
    public static function detach(): void
    {
        self::$socket = null;
        self::$readySent = false;
    }

    /** Whether this process runs under a supervisor. */
    public static function supervised(): bool
    {
        return self::$socket !== null;
    }

    /** The component is up. Sent once; later calls are ignored. */
    public static function ready(): void
    {
        if (self::$readySent) {
            return;
        }
        self::$readySent = true;
        self::send(self::READY);
    }

    /** The component ends because it was asked to stop. */
    public static function stopped(): void
    {
        self::send(self::STOPPED);
    }

    /** The component gave up by its own rules. */
    public static function failed(): void
    {
        self::send(self::FAILED);
    }

    /** The component cannot start: a process outside the application runs it. */
    public static function held(): void
    {
        self::send(self::HELD);
    }

    private static function send(string $message): void
    {
        if (self::$socket === null) {
            return;
        }
        // The supervisor may be gone already (the application is stopping): nothing to
        // tell, and nothing worth failing over.
        @fwrite(self::$socket, $message);
    }
}
