<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * One imported package, resolved.
 *
 * @link https://winterframe.net/docs/packages Packages
 */
final readonly class PluginPackage
{
    /**
     * @param string $package Composer name, e.g. `acme/billing`.
     * @param string $path Install path, without a trailing separator.
     * @param string|null $prefix URL prefix its controllers mount under; null mounts none.
     * @param list<string> $roots Absolute directories holding the package's own classes.
     */
    public function __construct(
        public string $package,
        public string $path,
        public ?string $prefix,
        public array $roots,
    ) {
    }

    public function mountsRoutes(): bool
    {
        return $this->prefix !== null;
    }
}
