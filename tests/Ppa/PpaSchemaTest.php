<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa;

use Flytachi\Winter\Kernel\App\Attribute\EnablePpaMigration;
use Flytachi\Winter\Kernel\Ppa\PpaSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a database keeps its schema history: ppa/<the config's short class name, as it is>.
 */
final class PpaSchemaTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'namespaced' => ['Main\Configurations\MainDbConfig', 'MainDbConfig'];
        yield 'short' => ['PgDb', 'PgDb'];
        yield 'nothing cut' => ['Main\ShopArchiveDb', 'ShopArchiveDb'];
    }

    #[DataProvider('names')]
    public function test_a_databases_folder_is_its_config_class_name(string $class, string $folder): void
    {
        self::assertSame($folder, PpaSchema::nameOf($class));
    }

    public function test_the_history_lives_under_ppa(): void
    {
        self::assertSame('ppa', PpaSchema::DIR);
    }

    public function test_a_folder_with_versions_of_no_database_is_a_stray(): void
    {
        $root = sys_get_temp_dir() . '/wk-ppa-strays-' . bin2hex(random_bytes(4));
        mkdir("{$root}/ppa/PgDb", 0777, true);
        mkdir("{$root}/ppa/OldDb");
        mkdir("{$root}/ppa/Empty");
        touch("{$root}/ppa/PgDb/V001_init.json");
        touch("{$root}/ppa/OldDb/V001_init.json");
        try {
            self::assertSame(['OldDb'], PpaSchema::strays([['name' => 'pgdb']], $root));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function test_enable_ppa_migration_is_a_class_attribute_of_the_manifest(): void
    {
        $attr = new \ReflectionClass(EnablePpaMigration::class)->getAttributes(\Attribute::class)[0]->newInstance();

        self::assertSame(\Attribute::TARGET_CLASS, $attr->flags);
    }
}
