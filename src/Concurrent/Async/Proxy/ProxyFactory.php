<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent\Async\Proxy;

use Flytachi\Winter\Kernel\Concurrent\Async\AsyncException;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Materialises generated proxies as files and loads them.
 *
 * Proxies are always written to disk rather than evaluated in memory. It costs
 * one `require` on first use and buys something worth more: stack traces, IDE
 * navigation and `php -l` all point at real, readable code.
 *
 * In development the proxy is regenerated whenever the original file is newer,
 * so editing a service and reloading is enough. Otherwise a missing file is
 * generated on first use and only checked for existence afterwards.
 *
 * They live next to the DI cache, in volatile storage, because they are the same
 * kind of artefact: derived from one scan, disposable, rebuilt on demand. That
 * also means they follow `Kernel::init(isTmpVolatile:)` — a project that wants
 * them inside the image only has to move volatile storage, not configure this
 * separately.
 *
 * @see ProxyGenerator
 */
final class ProxyFactory
{
    /** Sub-directory of the volatile store holding generated proxies. */
    public const string DIRECTORY = 'async';

    /**
     * @param string $directory Absolute path proxies are written to.
     * @param bool $refresh Whether a proxy older than its source must be rebuilt.
     */
    public function __construct(
        private readonly string $directory,
        private readonly bool $refresh = false
    ) {
    }

    /**
     * Builds a factory rooted in the kernel volatile store, beside the DI cache.
     *
     * @param bool|null $refresh Overrides the DEBUG-driven default.
     */
    public static function forKernel(?bool $refresh = null): self
    {
        return new self(
            Kernel::$pathStorageVolatile . DIRECTORY_SEPARATOR . self::DIRECTORY,
            $refresh ?? (bool) env('DEBUG', false)
        );
    }

    /**
     * Returns the directory generated proxies are written to.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Returns the loaded proxy class for the given class, generating it if needed.
     *
     * @param \ReflectionClass $class Class to proxy.
     * @return class-string Name of the generated subclass.
     * @throws AsyncException If generation or loading fails.
     */
    public function proxyFor(\ReflectionClass $class): string
    {
        /** @var class-string $proxyClass */
        $proxyClass = ProxyGenerator::proxyClass($class->getName());
        $file = $this->fileFor($class->getName());

        // The file is reconciled before the class is, because the two can
        // disagree: `call di build` clears the directory in a process whose
        // boot has already loaded the proxies, and skipping the write here
        // would leave the build reporting success over an empty directory.
        if ($this->isStale($file, $class)) {
            $this->write($file, ProxyGenerator::generate($class));
        }

        if (class_exists($proxyClass, false)) {
            return $proxyClass;
        }

        require_once $file;

        if (!class_exists($proxyClass, false)) {
            throw AsyncException::of(
                $class->getName(),
                'the generated proxy ' . $proxyClass . ' was not declared by ' . $file,
                'Delete the file and let it be regenerated.'
            );
        }

        return $proxyClass;
    }

    /**
     * Generates proxies for every given class without loading them.
     *
     * Used by the build step so a production image ships ready-made files.
     *
     * @param iterable<\ReflectionClass> $classes Classes to pre-build.
     * @return array<class-string, string> Original class name mapped to the written file.
     */
    public function warm(iterable $classes): array
    {
        $written = [];

        foreach ($classes as $class) {
            $file = $this->fileFor($class->getName());
            $this->write($file, ProxyGenerator::generate($class));
            $written[$class->getName()] = $file;
        }

        return $written;
    }

    /**
     * Removes every generated proxy file.
     *
     * @return int Number of files deleted.
     */
    public function clear(): int
    {
        $deleted = 0;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Returns the file a class's proxy is written to.
     *
     * @param string $class Fully qualified name of the original class.
     */
    public function fileFor(string $class): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . str_replace('\\', '_', ltrim($class, '\\'))
            . '.php';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param string $file Proxy file to check.
     * @param \ReflectionClass $class Class the proxy was generated from.
     */
    private function isStale(string $file, \ReflectionClass $class): bool
    {
        if (!is_file($file)) {
            return true;
        }

        if (!$this->refresh) {
            return false;
        }

        $source = $class->getFileName();

        return $source !== false && filemtime($source) > filemtime($file);
    }

    /**
     * Writes the proxy atomically, so a concurrent worker never sees half a file.
     *
     * @param string $file Destination path.
     * @param string $source Generated PHP source.
     */
    private function write(string $file, string $source): void
    {
        Kernel::ensureDirectory($this->directory);

        $temporary = $file . '.' . getmypid() . '.tmp';

        if (file_put_contents($temporary, $source, LOCK_EX) === false || !rename($temporary, $file)) {
            @unlink($temporary);

            throw AsyncException::of(
                $file,
                'the generated proxy could not be written',
                'Check that ' . $this->directory . ' exists and is writable.'
            );
        }
    }
}
