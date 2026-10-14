<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core\Proxy;

/**
 * One feature that works by overriding methods in a generated subclass.
 *
 * `#[Async]` and the cache attributes are both implemented this way, and a class
 * can only ever have one proxy — the container binds it to exactly one concrete
 * class. So the generator does not belong to either feature: it walks the class,
 * asks every enabled interceptor which methods it claims, and lets each one render
 * the bodies of its own methods into the same subclass.
 *
 * A method claimed by two interceptors is refused rather than stacked: the order
 * would change the meaning (caching a `Future` is not caching its value), and
 * nothing in the source would say which order applies.
 *
 * @internal
 */
interface MethodInterceptor
{
    /**
     * Short, stable name of the feature, e.g. `async`.
     *
     * Written into every generated file and the discovery cache, so a proxy built
     * for a different set of features is recognised as stale.
     */
    public function name(): string;

    /**
     * How the feature is spelled in the source, e.g. `#[Async]`, for messages.
     */
    public function label(): string;

    /**
     * Whether the method carries this feature's attributes.
     *
     * @param \ReflectionMethod $method Method to inspect.
     */
    public function intercepts(\ReflectionMethod $method): bool;

    /**
     * Renders the body of the overriding method.
     *
     * The signature is written by the generator; this returns only the statements
     * between the braces, unindented, one per line.
     *
     * @param \ReflectionMethod $method Method being overridden.
     * @param \ReflectionClass $class Class being proxied.
     * @param string $call Forwarding call to the original, e.g. `parent::find($id)`.
     * @return string PHP statements.
     * @throws ProxyException If the method breaks the feature's contract.
     */
    public function body(\ReflectionMethod $method, \ReflectionClass $class, string $call): string;

    /**
     * Builds the exception this feature reports its problems with.
     *
     * @param string $subject Fully qualified method or class the problem belongs to.
     * @param string $problem What is wrong, phrased as a statement.
     * @param string $remedy What the developer should do instead.
     */
    public function error(string $subject, string $problem, string $remedy): ProxyException;
}
