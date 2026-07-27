<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Attribute;

use Flytachi\Winter\K2\App\Scope;

/**
 * Marks a method of a {@see Configuration} class as a container factory — the
 * analogue of Spring's @Bean.
 *
 * The binding key is the method's return type (or {@see $name} if given). The
 * method body is the factory; its parameters are autowired from the container
 * (use {@see Value} for scalar values from .env). The result is scoped per
 * {@see $scope} — {@see Scope::Singleton} by default (built once, cached).
 *
 * ```
 * #[Bean]                                  // singleton, key = MailerInterface
 * public function mailer(#[Value('MAIL_HOST')] string $host): MailerInterface
 * {
 *     return new SmtpMailer($host);
 * }
 *
 * #[Bean(scope: Scope::Transient)]         // a fresh instance on every resolve
 * public function query(): QueryBuilder
 * {
 *     return new QueryBuilder();
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Bean
{
    /**
     * @param Scope $scope Lifetime of the produced instance (default: singleton).
     * @param string|null $name Explicit binding key; defaults to the return type.
     */
    public function __construct(
        public Scope $scope = Scope::Singleton,
        public ?string $name = null,
    ) {
    }
}
