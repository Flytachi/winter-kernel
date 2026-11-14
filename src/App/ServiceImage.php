<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * What a {@see Service} needs in the container image — on flytachi/winter, which is
 * Alpine: packages for apk, directories owned by winter, the volumes that keep its data,
 * the image's environment, and, rarely, commands of its own.
 *
 * Several services are merged into one block ({@see merge()}): one `apk add`, one
 * `mkdir`/`chown`, every volume once. Two services asking for one thing in two different
 * ways — a directory with two modes, a variable with two values — is an error, not a
 * silent choice of the last.
 */
final readonly class ServiceImage
{
    /** The marks around the block `call service docker` writes into a Dockerfile. */
    public const string BEGIN = '# winter:services';
    public const string END = '# winter:services end';

    /**
     * @param list<string> $packages apk packages: `['postgresql18', 'postgresql18-client']`.
     * @param array<string, int> $directories Created, owned by winter, with this mode:
     *     `['/var/lib/postgresql/data' => 0700]`.
     * @param array<string, string> $volumes Named volumes that keep data, name => path:
     *     `['postgres-data' => '/var/lib/postgresql/data']`.
     * @param array<string, string> $env The image's environment: `['PGDATA' => '/var/lib/postgresql/data']`.
     * @param list<string> $run Commands of its own, run as root after the packages and the
     *     directories — for what none of the above covers.
     * @throws \InvalidArgumentException A name, path or value the Dockerfile cannot take.
     */
    public function __construct(
        public array $packages = [],
        public array $directories = [],
        public array $volumes = [],
        public array $env = [],
        public array $run = [],
    ) {
        foreach ($packages as $package) {
            if (!is_string($package) || preg_match('/^[a-z0-9][a-z0-9._+-]*$/', $package) !== 1) {
                throw new \InvalidArgumentException("not an apk package name: \"{$package}\".");
            }
        }
        foreach ($directories as $path => $mode) {
            self::assertPath((string) $path);
            if (!is_int($mode) || $mode < 0 || $mode > 0o777) {
                throw new \InvalidArgumentException("directory {$path}: the mode must be 0–0777.");
            }
        }
        foreach ($volumes as $name => $path) {
            if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', (string) $name) !== 1) {
                throw new \InvalidArgumentException("not a volume name: \"{$name}\" (a-z, 0-9, _ . -).");
            }
            self::assertPath((string) $path);
        }
        foreach ($env as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) !== 1) {
                throw new \InvalidArgumentException("not a variable name: \"{$name}\".");
            }
            if (!is_string($value) || str_contains($value, "\n")) {
                throw new \InvalidArgumentException("variable {$name}: a value is one line of text.");
            }
        }
        foreach ($run as $command) {
            if (!is_string($command) || trim($command) === '' || str_contains($command, "\n")) {
                throw new \InvalidArgumentException('a run command is one non-empty line.');
            }
        }
    }

    /**
     * One image for several services: each package, directory, volume and variable once,
     * the commands in the order the services were declared.
     *
     * @throws \InvalidArgumentException Two services want one thing two ways.
     */
    public static function merge(self ...$images): self
    {
        $packages = $directories = $volumes = $env = $run = [];
        foreach ($images as $image) {
            foreach ($image->packages as $package) {
                $packages[$package] = true;
            }
            foreach ($image->directories as $path => $mode) {
                if (isset($directories[$path]) && $directories[$path] !== $mode) {
                    throw new \InvalidArgumentException(sprintf(
                        'directory %s is wanted with mode %04o and %04o.',
                        $path,
                        $directories[$path],
                        $mode,
                    ));
                }
                $directories[$path] = $mode;
            }
            foreach ($image->volumes as $name => $path) {
                if (isset($volumes[$name]) && $volumes[$name] !== $path) {
                    throw new \InvalidArgumentException("volume {$name} is wanted for {$volumes[$name]} and {$path}.");
                }
                $other = array_search($path, $volumes, true);
                if ($other !== false && $other !== $name) {
                    throw new \InvalidArgumentException("{$path} is wanted as volume {$other} and {$name}.");
                }
                $volumes[$name] = $path;
            }
            foreach ($image->env as $name => $value) {
                if (isset($env[$name]) && $env[$name] !== $value) {
                    throw new \InvalidArgumentException("{$name} is wanted as \"{$env[$name]}\" and \"{$value}\".");
                }
                $env[$name] = $value;
            }
            foreach ($image->run as $command) {
                if (!in_array($command, $run, true)) {
                    $run[] = $command;
                }
            }
        }
        return new self(array_keys($packages), $directories, $volumes, $env, $run);
    }

    /**
     * The Dockerfile block, between {@see BEGIN} and {@see END}: as root, the packages and
     * the directories in one layer, then the commands of its own; back to winter; then the
     * environment.
     */
    public function dockerfile(): string
    {
        $lines = [self::BEGIN . ' — written by `call service docker`; change the services, not this block'];
        $steps = [];
        if ($this->packages !== []) {
            $steps[] = 'apk add --no-cache ' . implode(' ', $this->packages);
        }
        if ($this->directories !== []) {
            $paths = array_keys($this->directories);
            $steps[] = 'mkdir -p ' . implode(' ', $paths);
            $steps[] = 'chown -R winter:winter ' . implode(' ', $paths);
            $byMode = [];
            foreach ($this->directories as $path => $mode) {
                $byMode[sprintf('%o', $mode)][] = $path;
            }
            ksort($byMode);
            foreach ($byMode as $mode => $group) {
                $steps[] = "chmod {$mode} " . implode(' ', $group);
            }
        }
        if ($steps !== [] || $this->run !== []) {
            $lines[] = 'USER root';
            if ($steps !== []) {
                $lines[] = 'RUN ' . implode(" \\\n    && ", $steps);
            }
            foreach ($this->run as $command) {
                $lines[] = 'RUN ' . $command;
            }
            $lines[] = 'USER winter';
        }
        foreach ($this->env as $name => $value) {
            $lines[] = sprintf('ENV %s=%s', $name, self::quote($value));
        }
        $lines[] = self::END;
        return implode("\n", $lines) . "\n";
    }

    private static function assertPath(string $path): void
    {
        $plain = preg_match('#^/[A-Za-z0-9._/-]*$#', $path) === 1 && !str_contains($path, '..');
        if (!$plain) {
            throw new \InvalidArgumentException("not an absolute path the image can take: \"{$path}\".");
        }
    }

    /** A value as ENV takes it: bare when it is plain, in double quotes otherwise. */
    private static function quote(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9._/:@%+=,-]+$#', $value) === 1) {
            return $value;
        }
        return '"' . addcslashes($value, '"\\$') . '"';
    }
}
