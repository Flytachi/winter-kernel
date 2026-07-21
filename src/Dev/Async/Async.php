<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Async;

/**
 * Marks a method to be executed asynchronously.
 *
 * Mirrors Spring's `Async`. The call returns immediately; the body runs on an
 * {@see \Flytachi\Winter\K2\Dev\Concurrent\ExecutorService} — a coroutine under
 * Swoole, a deferred task under FPM.
 *
 * The framework replaces the container binding of the declaring class with a
 * generated subclass, so nothing has to be wired by hand: annotate the method,
 * inject the service, done.
 *
 * ---
 * ### Contract
 *
 * - the method is `public`, not `static` and not `final`;
 * - the declaring class is not `final`;
 * - the return type is `Future` or `void`;
 * - a `Future`-returning body returns {@see \Flytachi\Winter\K2\Dev\Concurrent\CompletableFuture::completedFuture()};
 * - parameters are not passed by reference — a background task cannot write back.
 *
 * Violations are reported when proxies are generated, not at runtime.
 *
 * ---
 * ### Example
 *
 * ```
 * class NotificationService
 * {
 *     #[Async]
 *     public function track(int $userId, string $event): void
 *     {
 *         $this->mixpanel->push($userId, $event);
 *     }
 *
 *     #[Async]
 *     public function send(int $userId): Future
 *     {
 *         $this->mailer->send($userId);
 *
 *         return CompletableFuture::completedFuture(true);
 *     }
 * }
 * ```
 *
 * ---
 * ### Caveats
 *
 * Only instances obtained from the DI container are proxied. A service built
 * with `new` runs synchronously.
 *
 * Under Swoole a body that never suspends — no I/O, no sleep — finishes before
 * the call even returns, because a new coroutine is entered immediately. The
 * result is still correct; only the "runs later" intuition does not hold for
 * purely computational bodies.
 *
 * @see \Flytachi\Winter\K2\Dev\Concurrent\Future
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Async
{
    /**
     * @param string|null $executor Container id of the executor to run on; null uses the shared one.
     */
    public function __construct(
        public readonly ?string $executor = null
    ) {
    }
}
