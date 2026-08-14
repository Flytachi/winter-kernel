<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

/**
 * Injects a value read from the environment (.env) into a {@see Bean} method
 * parameter — the analogue of Spring's @Value("${...}").
 *
 * The container cannot autowire a scalar by type, so a bean parameter that needs
 * a configuration value carries this attribute. Resolution is `env($key, $default)`.
 *
 * ```
 * #[Bean]
 * public function mailer(#[Value('MAIL_HOST', 'localhost')] string $host): MailerInterface
 * {
 *     return new SmtpMailer($host);
 * }
 * ```
 *
 * @link https://winterframe.net/docs/dependency-injection Injecting a value from .env into a bean
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Value
{
    /**
     * @param string $key Environment variable name.
     * @param bool|int|float|string|null $default Fallback when the variable is unset.
     */
    public function __construct(
        public string $key,
        public bool|int|float|string|null $default = null,
    ) {
    }
}
