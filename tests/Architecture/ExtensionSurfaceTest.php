<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * A class is closed unless someone decided otherwise, in writing.
 *
 * `final` is the only thing that removes a class from an IDE's `extends` completion, so
 * an open class is public API whether or not anyone meant it to be. This test turns
 * openness from a default into a decision: every entry below carries the reason it stays
 * open, and a new class that is neither final nor listed fails the suite.
 *
 * Grep alone cannot decide this. ExceptionResponseBase has no subclass anywhere in the
 * repository, yet closing it would remove a documented extension point — the handlers
 * that extend it live in applications, not here. So the list is checked against
 * contracts (`#[Enable*]`, `#[Advice*]`, PHPDoc), not against usage counts.
 */
final class ExtensionSurfaceTest extends TestCase
{
    /**
     * Classes deliberately left open, each with the reason it stays open.
     *
     * Exceptions are absent on purpose: they are open as a category, since extending
     * ClientError in an application is normal and the lack of such subclasses here only
     * reflects that this repository is a library rather than an application.
     *
     * @var array<string, string>
     */
    private const array OPEN = [
        'Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler'
            => 'stereotype: #[EnableScheduler(MyScheduler::class)] extends it',
        'Flytachi\Winter\Kernel\Http\Stereotype\ExceptionResponseBase'
            => 'stereotype: #[AdviceException] handlers extend it',
        'Flytachi\Winter\Kernel\Process\ProcessStatus'
            => 'extended by DaemonStatus inside the kernel',
        'Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal\DateTime'
            => 'extended by Date, Time and Timestamp inside PPA',
        'Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Primal\FloatType'
            => 'extended by Double and Decimal inside PPA',
        'Flytachi\Winter\Kernel\Http\Health\HealthIndicator'
            => 'replaceable through #[EnableActuator(indicator: ...)]',
        'Flytachi\Winter\Kernel\Process\Daemon\ScalingPolicy'
            => 'policy object, documented as non-final so it can be refined',
        'Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy'
            => 'policy object, documented as non-final so it can be refined',
    ];

    public function test_every_concrete_class_is_final_or_listed_as_open(): void
    {
        $unlisted = [];

        foreach ($this->classesInSrc() as $fqcn) {
            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isInterface() || $reflection->isEnum() || $reflection->isTrait()) {
                continue;
            }
            if ($reflection->isAbstract() || $reflection->isFinal()) {
                continue;
            }
            if ($reflection->implementsInterface(Throwable::class)) {
                continue;
            }
            if (array_key_exists($fqcn, self::OPEN)) {
                continue;
            }

            $unlisted[] = $fqcn;
        }

        sort($unlisted);

        self::assertSame(
            [],
            $unlisted,
            'These classes are open for extension by accident. Mark each final, or add it '
            . 'to self::OPEN with the reason it must stay open.',
        );
    }

    /**
     * A list entry outliving its class is worse than no list: it reads as a decision
     * someone made, while guarding nothing.
     */
    public function test_the_open_list_has_no_stale_entries(): void
    {
        $stale = array_values(array_filter(
            array_keys(self::OPEN),
            static fn (string $fqcn): bool => !class_exists($fqcn),
        ));

        self::assertSame([], $stale, 'These entries name classes that no longer exist.');
    }

    /**
     * The listed classes must actually be open, or the entry is a leftover that hides a
     * class someone has since closed.
     */
    public function test_every_listed_class_is_really_open(): void
    {
        $closed = [];

        foreach (array_keys(self::OPEN) as $fqcn) {
            if (class_exists($fqcn) && new ReflectionClass($fqcn)->isFinal()) {
                $closed[] = $fqcn;
            }
        }

        self::assertSame([], $closed, 'These are final already — drop them from self::OPEN.');
    }

    /** @return list<string> */
    private function classesInSrc(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $classes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $fqcn = 'Flytachi\Winter\Kernel\\' . str_replace('/', '\\', $relative);

            if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }
}
