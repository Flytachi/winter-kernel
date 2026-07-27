<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Attribute;

/**
 * Marks a class as a bean container — the analogue of Spring's @Configuration.
 *
 * The scanner discovers every #[Configuration] class and registers each of its
 * {@see Bean}-annotated methods as a factory in the DI container. The class itself
 * is registered as a singleton, so all of its bean methods share one instance.
 *
 * ```
 * #[Configuration]
 * final class AppConfig
 * {
 *     #[Bean]
 *     public function cache(): CacheInterface
 *     {
 *         return new RedisCache(env('REDIS_URL'));
 *     }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Configuration
{
}
