<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * How the image builds a frontend: a stage of its own on a Node image — the manifest
 * copied first, so the install is cached while the dependencies do not change, then the
 * sources and the build — and only the output copied into the application's image.
 * `call service docker` writes it.
 */
final readonly class FrontBuild
{
    /**
     * @param string $context The frontend's folder, from the project root: `frontend`.
     * @param string $output What the build makes, from `$context`: `.output/public`.
     * @param list<string> $manifest The files the install needs, from `$context`:
     *     `package.json` and the lock file.
     * @param string $install The install command: `pnpm install --frozen-lockfile`.
     * @param string $build The build command: `pnpm generate`.
     * @param string $image The Node image the stage builds on.
     * @throws \InvalidArgumentException A value the Dockerfile cannot take.
     */
    public function __construct(
        public string $context,
        public string $output,
        public array $manifest,
        public string $install,
        public string $build,
        public string $image = 'node:24-alpine',
    ) {
        foreach (['context' => $context, 'output' => $output] as $what => $path) {
            // No `.` segment: the image empties the frontend's folder before its build is
            // copied in, and `.` would be the whole application.
            $segments = explode('/', $path);
            $plain = preg_match('#^[A-Za-z0-9._][A-Za-z0-9._/-]*$#', $path) === 1 && !str_contains($path, '..');
            if (!$plain || in_array('.', $segments, true) || in_array('', $segments, true)) {
                throw new \InvalidArgumentException("{$what} must be a relative path, \"{$path}\" given.");
            }
        }
        $names = array_filter($manifest, static fn($file): bool => is_string($file) && basename($file) === $file);
        if ($manifest === [] || count($names) !== count($manifest)) {
            throw new \InvalidArgumentException('manifest is a list of file names, package.json among them.');
        }
        foreach (['install' => $install, 'build' => $build, 'image' => $image] as $what => $value) {
            if (trim($value) === '' || str_contains($value, "\n")) {
                throw new \InvalidArgumentException("{$what} is one line.");
            }
        }
    }
}
