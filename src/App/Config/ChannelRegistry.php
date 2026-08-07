<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Flytachi\Winter\Kernel\Kernel;

/**
 * Fluent handle passed to {@see LoggingConfigurer::configureChannels()}. Declares
 * additional log channels beyond the built-in `http` / `sys`; each reads its
 * `LOG_{NAME}_*` variables from .env.
 *
 * ```
 * $channels->add('job')->add('audit');
 * ```
 */
final class ChannelRegistry
{
    /** @var list<string> */
    private array $names = [];

    public function add(string $name): self
    {
        $this->names[] = $name;
        return $this;
    }

    /**
     * Registers every declared channel with the kernel.
     */
    public function apply(): void
    {
        foreach ($this->names as $name) {
            Kernel::channel($name);
        }
    }
}
