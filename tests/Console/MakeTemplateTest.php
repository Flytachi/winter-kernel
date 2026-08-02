<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every class a generator template imports must exist.
 *
 * Templates hold fully-qualified names as plain text, so no static analysis and no
 * refactoring tool touches them. A template naming a class that was moved or removed
 * keeps generating code which fatals on autoload, and nothing reports it until a user
 * runs the generator — which is how two templates here went stale across a rename.
 */
final class MakeTemplateTest extends TestCase
{
    /**
     * OpenApi is parked, so its template is knowingly stale. Listing it here keeps the
     * exclusion a decision that someone made rather than an oversight.
     */
    private const array PARKED = ['OpenApiTemplate'];

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        $root = dirname(__DIR__, 2) . '/console/Template';
        $cases = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || in_array($file->getFilename(), self::PARKED, true)) {
                continue;
            }

            // Only PHP templates carry imports; Docker, shell and completion files do not.
            $contents = (string) file_get_contents($file->getPathname());
            if (!str_starts_with($contents, '<?php')) {
                continue;
            }

            $cases[$file->getFilename()] = [$file->getPathname()];
        }

        ksort($cases);

        return $cases;
    }

    #[DataProvider('templates')]
    public function test_every_imported_class_exists(string $path): void
    {
        preg_match_all('/^use ([^;]+);/m', (string) file_get_contents($path), $matches);

        // A template may legitimately import nothing — a DTO is a plain class, and the
        // PhpStorm meta file is not a class at all.
        $this->addToAssertionCount(1);

        foreach ($matches[1] as $import) {
            $fqcn = trim(explode(' as ', $import)[0]);

            self::assertTrue(
                class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn),
                sprintf('%s imports %s, which does not exist.', basename($path), $fqcn),
            );
        }
    }
}
