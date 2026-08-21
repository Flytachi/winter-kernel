<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Core;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Built-in commands are closed.
 *
 * They are wiring, not API: an application writes its own command by extending
 * CmdCustom. Left open they show up in `extends` completion, and two of them —
 * Process and Daemon — share a short name with a real extension point, so the
 * developer reaching for the base class cannot tell which entry is which.
 * Closing them removes the shadowing without renaming anything.
 */
final class CommandSurfaceTest extends TestCase
{
    public function test_every_built_in_command_is_final(): void
    {
        $open = [];

        foreach ($this->commands() as $fqcn) {
            if (!new ReflectionClass($fqcn)->isFinal()) {
                $open[] = $fqcn;
            }
        }

        sort($open);

        self::assertSame([], $open, 'Built-in commands must be final — they are not extension points.');
    }

    /**
     * The names that collide with a stereotype are the reason this test exists, so they
     * are asserted by name: a future command reusing a stereotype's short name would
     * reintroduce the ambiguity even while every command is final.
     */
    public function test_the_commands_shadowing_a_stereotype_stay_closed(): void
    {
        foreach (['Process', 'Daemon'] as $shadowed) {
            $command = 'Flytachi\Winter\Console\Command\\' . $shadowed;
            $stereotype = 'Flytachi\Winter\Kernel\Process\Stereotype\\' . $shadowed;

            self::assertTrue(class_exists($command), "{$command} is expected to exist.");
            self::assertTrue(class_exists($stereotype), "{$stereotype} is expected to exist.");
            self::assertTrue(
                new ReflectionClass($command)->isFinal(),
                "{$command} shares a short name with {$stereotype}; leaving it open makes "
                . 'both appear in `extends` completion.',
            );
        }
    }

    /**
     * The rest of the console is closed too.
     *
     * Only Command/ was the reported problem, but leaving the surrounding classes open
     * would let the next one drift back in unnoticed. The single extension point here is
     * CmdCustom, which is abstract and therefore never counted.
     */
    public function test_the_rest_of_the_console_is_closed(): void
    {
        $open = [];

        foreach ($this->consoleClasses() as $fqcn) {
            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isInterface() || $reflection->isEnum() || $reflection->isTrait()) {
                continue;
            }
            if ($reflection->isAbstract() || $reflection->isFinal()) {
                continue;
            }

            $open[] = $fqcn;
        }

        sort($open);

        self::assertSame([], $open, 'The CLI is wiring, not API — close these or make them abstract.');
    }

    /** @return list<string> */
    private function commands(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 2) . '/console/Command/*.php') ?: [] as $file) {
            $fqcn = 'Flytachi\Winter\Console\Command\\' . basename($file, '.php');

            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * Every alias points at a command that exists.
     *
     * The map is a plain array resolved before the autoloader is asked, so a typo or a
     * renamed command turns `call <alias>` into "Unknown command" — the alias silently
     * stops working while the full name keeps going, which is the hardest version of
     * this to notice.
     */
    public function test_every_alias_resolves_to_a_command(): void
    {
        $broken = [];

        foreach (Core::getAliases() as $alias => $command) {
            if (!class_exists('Flytachi\Winter\Console\Command\\' . $command)) {
                $broken[] = "{$alias} → {$command}";
            }
        }

        self::assertSame([], $broken, 'an alias must point at a real command');
    }

    /**
     * `sc` is the only alias, and that is a decision rather than an accident.
     *
     * `proc`, `dmn` and `sch` were removed: an alias earns its place by being guessable,
     * and those three cost a trip to the documentation to save three keystrokes. `script`
     * stays abbreviated because it prefixes every custom command an application has, so
     * it is the one name typed repeatedly in a session.
     *
     * Pinning the whole map keeps a new alias a considered addition instead of a habit.
     */
    public function test_the_alias_map_is_deliberately_small(): void
    {
        self::assertSame(['sc' => 'Script'], Core::getAliases());
    }

    /** @return list<string> */
    private function consoleClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/console';
        $classes = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $fqcn = 'Flytachi\Winter\Console\\' . str_replace('/', '\\', $relative);

            if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }
}
