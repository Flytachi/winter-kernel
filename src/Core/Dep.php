<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

/**
 * An optional package the kernel integrates with when it is installed.
 *
 * @see DepSupport
 */
enum Dep: string
{
    /** Repositories, entity mapping, migrations, the database connection pool. */
    case Ppa = 'ppa';

    /** Pooled Redis: prefixed stores, hashes, lists, streams. */
    case Redis = 'redis';

    /** Composer package name, as it goes into `composer require`. */
    public function package(): string
    {
        return match ($this) {
            self::Ppa   => 'flytachi/winter-ppa',
            self::Redis => 'flytachi/winter-redis',
        };
    }

    /** What it is, for an error message a human reads. */
    public function title(): string
    {
        return match ($this) {
            self::Ppa   => 'the database layer',
            self::Redis => 'the Redis layer',
        };
    }
}
