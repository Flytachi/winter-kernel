<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\App\Runtime\ManagedBy;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Requests to the application's supervisor about one of its components — how the
 * console, or code in any process of the application, starts or restarts a component
 * the application runs.
 *
 * A request is a small record per component; the supervisor picks it up within half a
 * second and removes it. It names the run of the application it is meant for: one
 * addressed to a previous run (the application restarted meanwhile), or older than a
 * minute, is dropped — what the operator asked of a run that is gone does not carry
 * over to the next.
 *
 * Stopping needs no request: a stop signal to the component is one, and the supervisor
 * leaves a component stopped on request down.
 *
 * @internal Between the framework's control surface and the supervisor.
 */
final class ComponentControl
{
    public const string START = 'start';
    public const string RESTART = 'restart';

    /** Seconds a request stays valid. */
    private const int TTL = 60;

    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Asks the supervisor of a run to start or restart a component.
     *
     * @param class-string $class
     */
    public static function request(ManagedBy $run, string $class, string $action): void
    {
        self::store()->write(self::key($class), [
            'action' => $action,
            'run'    => $run,
            'at'     => time(),
        ]);
    }

    /**
     * Takes the pending request for a component, if one is meant for this run.
     *
     * @param class-string $class
     * @return string|null {@see START}, {@see RESTART}, or null.
     */
    public static function take(ManagedBy $run, string $class): ?string
    {
        $store = self::store();
        $key = self::key($class);
        try {
            $request = $store->read($key);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($request)) {
            return null;
        }
        $store->del($key);

        $for = $request['run'] ?? null;
        $fresh = time() - (int) ($request['at'] ?? 0) <= self::TTL;
        if (!$for instanceof ManagedBy || $for != $run || !$fresh) {
            return null;
        }
        $action = $request['action'] ?? null;
        return $action === self::START || $action === self::RESTART ? $action : null;
    }

    private static function store(): FileStorage
    {
        return Kernel::runnable('control');
    }

    private static function key(string $class): string
    {
        return str_replace('\\', '.', $class);
    }
}
