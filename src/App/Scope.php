<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * Lifetime of a {@see Attribute\Bean} in the container.
 *
 * Mirrors the three container scopes one-to-one:
 *   - Singleton → one instance per process (the bean method runs once, cached).
 *   - Transient → a fresh instance on every resolve.
 *   - Request   → one instance per HTTP request / coroutine (singleton under FPM/CLI).
 *
 * A {@see Attribute\Bean} defaults to {@see Scope::Singleton}, matching Spring's
 * default @Bean scope.
 */
enum Scope
{
    case Singleton;
    case Transient;
    case Request;
}
