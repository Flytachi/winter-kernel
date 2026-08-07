<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

use Flytachi\Winter\Base\Runtime;

/**
 * Storage for values that belong to one unit of work — the request analogue of Java's
 * `ThreadLocal`.
 *
 * Under Swoole a worker serves many requests at once as coroutines **inside one
 * process**, so anything kept in a static property or a PHP engine global is shared by
 * all of them. A value stashed there by one request is visible to — and overwritable
 * by — every other request in flight. The failure is silent: it only shows up when two
 * concurrent users differ, and then one of them is served under the other's identity.
 *
 * This class puts such values where they belong: in the coroutine's own context, which
 * Swoole discards when the coroutine ends. Outside a coroutine (FPM, CLI, a plain
 * process) there is exactly one unit of work per process, so a static array is the
 * correct equivalent — and the caller does not have to know which runtime it is on.
 *
 * ```
 * RequestLocal::set('timezone', 'Asia/Tashkent');
 * RequestLocal::get('timezone');            // → 'Asia/Tashkent', for this request only
 * ```
 *
 * This is the **mechanism**. Prefer a typed facade over it for anything an application
 * touches — {@see \Flytachi\Winter\Kernel\Localization\Timezone} is the shape to copy:
 * a named accessor an IDE can complete beats a string key it cannot.
 *
 * Not to be confused with the DI **request scope** (`#[Request]`), which decides how
 * long a *bean* lives. This decides where a *value* is kept.
 */
final class RequestLocal
{
    /** Fallback for FPM / CLI / plain processes — one unit of work per process. */
    private static array $static = [];

    private function __construct()
    {
    }

    /** Stores a value for the current unit of work. */
    public static function set(string $key, mixed $value): void
    {
        if (Runtime::isSwooleCoroutine()) {
            \Swoole\Coroutine::getContext()[$key] = $value;
            return;
        }
        self::$static[$key] = $value;
    }

    /** Reads a value stored for the current unit of work, or $default when absent. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (Runtime::isSwooleCoroutine()) {
            return \Swoole\Coroutine::getContext()[$key] ?? $default;
        }
        return self::$static[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        if (Runtime::isSwooleCoroutine()) {
            return isset(\Swoole\Coroutine::getContext()[$key]);
        }
        return isset(self::$static[$key]);
    }

    /** Drops one value. A coroutine's context is discarded wholesale when it ends. */
    public static function forget(string $key): void
    {
        if (Runtime::isSwooleCoroutine()) {
            unset(\Swoole\Coroutine::getContext()[$key]);
            return;
        }
        unset(self::$static[$key]);
    }

    /**
     * Clears every value of the current unit of work.
     *
     * Only meaningful off the coroutine path, where the static fallback outlives the
     * request: a long-running process that handles units of work in a loop must reset
     * between them. Inside a coroutine this is a no-op in practice — the context dies
     * with the coroutine — but it is still honoured so callers need no runtime check.
     */
    public static function clear(): void
    {
        if (Runtime::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            foreach (array_keys((array) $ctx) as $key) {
                unset($ctx[$key]);
            }
            return;
        }
        self::$static = [];
    }
}
