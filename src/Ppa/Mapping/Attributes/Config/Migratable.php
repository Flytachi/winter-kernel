<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Config;

use Attribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\AttributeDbConfig;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\MigratablePriority;

/**
 * Opts a DbConfig into `db migrate` / `db sql` tooling.
 *
 * Configs without this attribute are silently skipped by the migration
 * commands (their entities are not considered, no SQL is generated, no
 * statements are executed). Read/write operations through the ORM are
 * unaffected — this attribute controls only the migration tooling.
 *
 * `priority` orders multiple migratable configs:
 *   High   → runs first
 *   Normal → default
 *   Low    → runs last
 *
 * ```
 * #[Migratable]
 * #[Extension('uuid-ossp')]
 * final class MainDbConfig extends DbConfig { ... }
 *
 * #[Migratable(priority: MigratablePriority::Low)]
 * final class AnalyticsDbConfig extends DbConfig { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Migratable implements AttributeDbConfig
{
    public function __construct(
        public MigratablePriority $priority = MigratablePriority::Normal,
    ) {
    }
}
