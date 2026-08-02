<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent\Async\Proxy;

/**
 * Finds places where an {@see \Flytachi\Winter\Kernel\Concurrent\Async\Async} service is
 * built with `new` instead of being taken from the container.
 *
 * Such a call gets the original class, not the proxy, so the annotated method
 * runs synchronously — correct result, wrong timing, and nothing in the code
 * says so: same return type, same `instanceof`, no error. The mistake is
 * invisible at runtime, which is exactly why it is worth reporting at build
 * time.
 *
 * ---
 * ### Deliberately a warning
 *
 * The scan reads source text, so it is a heuristic and cannot be exhaustive:
 *
 * - dynamic construction (`new $class`, factories, names from config) is invisible;
 * - a `new` inside tests is usually intentional — synchronous is what a test wants;
 * - `new self()` / `new static()` inside the service itself are not bypasses.
 *
 * It reports what it can prove textually and never fails a build.
 *
 * @see ProxyGenerator
 */
final class BypassScanner
{
    /** Names that never denote another class. */
    private const array SELF_REFERENCES = ['self', 'static', 'parent'];

    /** @var array<string, true> Fully qualified names of classes carrying #[Async]. */
    private array $targets;

    /**
     * @param iterable<class-string> $asyncClasses Classes whose instances must come from the container.
     * @param list<string> $exclude Absolute directory prefixes to skip.
     */
    public function __construct(iterable $asyncClasses, private readonly array $exclude = [])
    {
        $this->targets = [];
        foreach ($asyncClasses as $class) {
            $this->targets[ltrim($class, '\\')] = true;
        }
    }

    /**
     * Walks a project tree and reports every direct instantiation found.
     *
     * @param string $rootDir Directory to scan.
     * @return list<array{file: string, line: int, class: class-string}> Findings in file order.
     */
    public function scan(string $rootDir): array
    {
        if ($this->targets === []) {
            return [];
        }

        $findings = [];

        foreach ($this->files($rootDir) as $file) {
            foreach ($this->scanFile($file) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Reports direct instantiations inside a single file.
     *
     * @param string $file Absolute path of a PHP file.
     * @return list<array{file: string, line: int, class: class-string}>
     */
    public function scanFile(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return [];
        }

        // Cheap pre-filter: no `new` at all means no work to do.
        if (!str_contains($source, 'new')) {
            return [];
        }

        $tokens = \PhpToken::tokenize($source);
        $namespace = '';
        $aliases = [];
        $depth = 0;
        $findings = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is('{')) {
                $depth++;
                continue;
            }
            if ($token->is('}')) {
                $depth--;
                continue;
            }

            if ($token->is(T_NAMESPACE)) {
                $namespace = $this->readName($tokens, $i);
                continue;
            }

            // Only top-level `use` imports classes; inside a body it is a trait
            // import or a closure binding.
            if ($token->is(T_USE) && $depth === 0) {
                $this->readUse($tokens, $i, $aliases);
                continue;
            }

            if (!$token->is(T_NEW)) {
                continue;
            }

            $name = $this->readName($tokens, $i);
            if ($name === '' || in_array(strtolower($name), self::SELF_REFERENCES, true)) {
                continue;
            }

            $resolved = $this->resolve($name, $namespace, $aliases);
            if (isset($this->targets[$resolved])) {
                $findings[] = ['file' => $file, 'line' => $token->line, 'class' => $resolved];
            }
        }

        return $findings;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Yields every PHP file under the root that is not excluded.
     *
     * @param string $rootDir Directory to walk.
     * @return \Generator<string>
     */
    private function files(string $rootDir): \Generator
    {
        $root = rtrim($rootDir, '/\\');
        $skip = array_map(static fn(string $dir): string => rtrim($dir, '/\\'), $this->exclude);
        $skip[] = $root . DIRECTORY_SEPARATOR . 'vendor';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            foreach ($skip as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }

            yield $path;
        }
    }

    /**
     * Reads the name that follows the token at $index, advancing past it.
     *
     * @param list<\PhpToken> $tokens Token stream.
     * @param int $index Cursor, moved to the last consumed token.
     */
    private function readName(array $tokens, int &$index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $index = $i;

                return $token->text;
            }

            // Anything else — `new $var`, `new (expr)`, `new class {…}` — is not a name.
            return '';
        }

        return '';
    }

    /**
     * Records the imports of a top-level `use` statement.
     *
     * Handles plain, aliased and grouped forms; skips `use function` / `use const`
     * and closure bindings.
     *
     * @param list<\PhpToken> $tokens Token stream.
     * @param int $index Cursor, moved to the end of the statement.
     * @param array<string, string> $aliases Alias map to fill, short name to FQCN.
     */
    private function readUse(array $tokens, int &$index, array &$aliases): void
    {
        $prefix = '';
        $current = '';
        $alias = null;
        $expectAlias = false;

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            // `use function foo;`, `use const BAR;` and closure `use (...)`.
            if ($token->is([T_FUNCTION, T_CONST, '('])) {
                $index = $i;

                return;
            }

            if ($token->is(T_AS)) {
                $expectAlias = true;
                continue;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                if ($expectAlias) {
                    $alias = $token->text;
                } else {
                    $current = $token->text;
                }
                continue;
            }

            if ($token->is('{')) {
                $prefix = rtrim($current, '\\') . '\\';
                $current = '';
                continue;
            }

            if ($token->is(',') || $token->is('}') || $token->is(';')) {
                if ($current !== '') {
                    $fqcn = ltrim($prefix . $current, '\\');
                    $aliases[$alias ?? $this->shortName($current)] = $fqcn;
                }

                $current = '';
                $alias = null;
                $expectAlias = false;

                if ($token->is(';')) {
                    $index = $i;

                    return;
                }
                if ($token->is('}')) {
                    $prefix = '';
                }
            }
        }

        $index = $count - 1;
    }

    /**
     * Turns a name as written into a fully qualified one.
     *
     * @param string $name Name as it appears after `new`.
     * @param string $namespace Namespace of the file.
     * @param array<string, string> $aliases Import map of the file.
     */
    private function resolve(string $name, string $namespace, array $aliases): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $first = array_shift($segments);

        if (isset($aliases[$first])) {
            return $segments === []
                ? $aliases[$first]
                : $aliases[$first] . '\\' . implode('\\', $segments);
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * @param string $name Possibly qualified name.
     */
    private function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
