<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

abstract class KernelConfig
{
    public static string $pathRoot;
    public static string $pathEnv;
    public static string $pathResource;
    /**
     * Where logs go when they are written to files (`LOG_OUTPUT=file`): `LOG_DIR`,
     * relative to the project root unless absolute — `<root>/logs` without it. Resolved
     * once the environment is read, and created at boot only when logs go to files — so
     * a directory that cannot be written stops the boot instead of the first log line.
     */
    public static string $pathLogs;

    /**
     * The framework's own folder in the project root: what it writes for itself, none of
     * it the application's to edit or to commit. Fixed — no setting moves it — so every
     * process of the project, the console on the host and the application in a container
     * alike, finds the same files. One line ignores it: `/.winter`.
     */
    public static string $pathWinter;

    /** `#[Cacheable]` values kept in files (the `file` store). */
    public static string $pathCache;

    /**
     * The state of the project's running processes: their records, the locks that keep
     * one process per class and one run per application, and what the console and the
     * application's supervisor say to each other. Shared by every process of the
     * project — the console on the host reads what the application in a container
     * writes — so it lives in the project, not in a temp directory.
     */
    public static string $pathRunnable;

    /**
     * What the framework builds from the project's code to start faster: the class list
     * (`di.php`) and the `#[Async]` / `#[Cacheable]` proxies. Safe to delete at any time —
     * `call di build` or the next start writes it again. It used to sit in the system
     * temp directory under the project folder's *name*, so two projects named alike
     * read each other's class list.
     */
    public static string $pathBuild;

    /**
     * @param string|null $pathRoot
     * @param string|null $pathEnv
     * @param string|null $pathResource
     * @return void
     */
    public static function init(
        ?string $pathRoot = null,
        ?string $pathEnv = null,
        ?string $pathResource = null,
    ): void {
        // root
        if ($pathRoot === null) {
            $pathRoot = dirname(__DIR__, 5);
        }

        // env
        if ($pathEnv === null) {
            $pathEnv = $pathRoot . '/.env';
        }

        // resource
        if ($pathResource === null) {
            $pathResource = $pathRoot . '/resources';
        }

        self::$pathRoot = $pathRoot;
        self::$pathEnv = $pathEnv;
        self::$pathResource = $pathResource;
        self::$pathLogs = $pathRoot . '/logs';
        self::$pathWinter = $pathRoot . '/.winter';
        self::$pathCache = self::$pathWinter . '/cache';
        self::$pathBuild = self::$pathWinter . '/build';
        self::$pathRunnable = self::$pathWinter . '/runnable';
    }
}
