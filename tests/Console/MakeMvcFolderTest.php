<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Command\Make;
use Flytachi\Winter\Kernel\Core\KernelConfig;
use PHPUnit\Framework\TestCase;

/**
 * Where `--mvc` files a generated class. The class name's suffix doubled as the folder
 * key, and an entity has no suffix (`User`, not `UserEntity`), so it fell through to
 * `Utils/`.
 */
final class MakeMvcFolderTest extends TestCase
{
    /** @return array{namespace: string, className: string} */
    private function info(string $prefix, string $template, ?string $category = null): array
    {
        // The paths of this repository: its PSR-4 roots are where `make` would write.
        KernelConfig::init(pathRoot: dirname(__DIR__, 2));
        $make = new Make(['options' => ['mvc' => true], 'flags' => [], 'arguments' => []]);
        (new \ReflectionProperty(Make::class, 'templatePath'))->setValue($make, dirname(__DIR__, 2) . '/console/Template/Make');
        return (new \ReflectionMethod(Make::class, 'getInfo'))
            ->invoke($make, 'User', $prefix, $template, $category);
    }

    public function test_an_entity_goes_to_entities(): void
    {
        $info = $this->info('', 'EntityTemplate', 'Entity');

        self::assertSame('User', $info['className']);
        self::assertStringEndsWith('\\Entities', $info['namespace']);
    }

    public function test_a_suffixed_class_still_goes_by_its_suffix(): void
    {
        $info = $this->info('Repository', 'RepositoryTemplate');

        self::assertSame('UserRepository', $info['className']);
        self::assertStringEndsWith('\\Repositories', $info['namespace']);
    }
}
