<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Config;

use Attribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDbConfig;

/**
 * Declares a database extension required by the configuration.
 *
 * Stack one attribute per extension on a DbConfig class. Aggregated by
 * {@see \Flytachi\Winter\Kernel\Ppa\DeclarationItem} and emitted as
 * `CREATE EXTENSION IF NOT EXISTS …` at migration time.
 *
 * Driver support: PostgreSQL only. Putting this attribute on a non-pgsql
 * config will be skipped silently by tooling (the structure object throws
 * if asked to render SQL for another dialect).
 *
 * ```
 * #[Extension('uuid-ossp')]
 * #[Extension('pgcrypto', cascade: true)]
 * #[Extension('postgis', version: '3.4', schema: 'gis')]
 * final class MainDbConfig extends DbConfig { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Extension implements AttributeDbConfig
{
    public function __construct(
        public string $name,
        public ?string $version = null,
        public ?string $schema = null,
        public bool $cascade = false,
    ) {
    }
}
