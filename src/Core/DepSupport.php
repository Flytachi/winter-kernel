<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

use RuntimeException;

/**
 * Which optional packages this application installed.
 *
 * The framework carries what every application needs and no more. A database layer, a
 * Redis client — those are `composer require` decisions, and an application that serves
 * HTTP from an external API should not ship an ORM, a connection pool and a migration
 * engine it never loads.
 *
 * That makes every point where the kernel reaches into an optional package conditional,
 * and this is the single place that answers the condition. The alternative —
 * `class_exists()` spread through the kernel — hides that these are all the same decision
 * and makes it impossible to see what happens when the answer is no.
 *
 * Adding a package here means adding a case to {@see Dep} and a probe class below.
 */
final class DepSupport
{
    /**
     * A class that exists only when the package does. Deliberately something central
     * rather than an interface a user might implement by accident.
     */
    private const array PROBE = [
        Dep::Ppa->value   => 'Flytachi\Winter\Ppa\Pool\PpaConnectionPool',
        Dep::Redis->value => 'Flytachi\Winter\Redis\RedisPool',
    ];

    /** @var array<string, bool> */
    private static array $installed = [];

    /** Static-only. */
    private function __construct()
    {
    }

    /**
     * `true` when the package is present.
     *
     * Cached: the answer cannot change while the process lives, and this is asked on the
     * request path — health probes, worker start and stop.
     */
    public static function has(Dep $dep): bool
    {
        return self::$installed[$dep->value] ??= class_exists(self::PROBE[$dep->value]);
    }

    /**
     * Demands a package, naming what needed it and how to get it.
     *
     * For entry points that cannot do anything useful without it — the `db` command, the
     * generators. Without this they would die with `Class "…" not found`, which reads
     * like a broken framework rather than a package the application chose not to install.
     *
     * @throws RuntimeException
     */
    public static function demand(Dep $dep, string $what): void
    {
        if (self::has($dep)) {
            return;
        }

        throw new RuntimeException(
            $what . ' needs ' . $dep->title() . ', which is not installed.' . PHP_EOL
            . 'Add it with:  composer require ' . $dep->package(),
        );
    }

    /** Test seam: forget the cached answers. */
    public static function forget(): void
    {
        self::$installed = [];
    }
}
