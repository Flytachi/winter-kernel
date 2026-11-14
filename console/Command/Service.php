<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\InstalledVersions;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\App\Service as AppService;
use Flytachi\Winter\Kernel\App\ServiceImage;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * The services the application runs inside its container — a database, a cache — as
 * {@see \Flytachi\Winter\Kernel\App\Components::service()} declares them.
 *
 * `list` shows them; `docker` writes what the image needs for them into the Dockerfile
 * (a block between marks, replaced on every run) and their volumes and stop time into
 * `docker-compose.override.yml`, which Compose reads beside `docker-compose.yml` — the
 * project's own compose file is never edited.
 */
final class Service extends Cmd
{
    public static string $title = "the services the application runs in its container: list | docker";

    /** The first line of the override file this command owns. */
    private const string OVERRIDE_MARK = '# Written by `call service docker`';

    public function handle(): void
    {
        self::printTitle("Service", 34);
        try {
            match ($this->args['arguments'][1] ?? '') {
                'list' => $this->listArg(),
                'docker' => $this->dockerArg(),
                '' => self::help(),
                default => $this->unknown((string) $this->args['arguments'][1]),
            };
        } catch (\Throwable $e) {
            self::printWarning($e->getMessage());
        }
        self::printTitle("Service", 34);
    }

    private function unknown(string $sub): void
    {
        self::printWarning("Unknown argument '{$sub}'");
        self::printInfo("Run 'call service --help' to see available commands.");
    }

    // ── list ──────────────────────────────────────────────────────────────────

    private function listArg(): void
    {
        $services = $this->services();
        if ($services === []) {
            self::printInfo('No services declared. Add one in components(): $components->service(new …).');
            return;
        }
        foreach ($services as $service) {
            $component = $service->component();
            $image = $service->image();
            self::printLabel($component->name(), 34);
            self::printKeyValue('class', $service::class, 12, 34);
            self::printKeyValue('package', self::packageOf($service::class), 12, 34);
            self::printKeyValue('priority', $component->priority->name, 12, 34);
            self::printKeyValue('packages', $image->packages === [] ? '-' : implode(' ', $image->packages), 12, 34);
            foreach ($image->volumes as $name => $path) {
                self::printKeyValue('volume', "{$name} → {$path}", 12, 34);
            }
            foreach ($image->env as $name => $value) {
                self::printKeyValue('env', "{$name}={$value}", 12, 34);
            }
            self::printLabel($component->name(), 34);
        }
        self::printInfo('`call service docker` writes what they need into the Dockerfile.');
    }

    // ── docker ────────────────────────────────────────────────────────────────

    private function dockerArg(): void
    {
        $dry = array_key_exists('dry', $this->args['options']);
        $services = $this->services();
        $image = ServiceImage::merge(...array_map(static fn(AppService $s): ServiceImage => $s->image(), $services));

        $this->dockerfile($services === [] ? null : $image, $dry);
        $this->override($services === [] ? [] : $image->volumes, $dry);

        if ($dry) {
            self::printInfo('--dry: nothing was written.');
            return;
        }
        if ($services !== []) {
            self::printInfo('Rebuild the image to take it in:  docker compose up -d --build');
        }
    }

    /** The block in the Dockerfile: replaced when it is there, put in front of the code when not. */
    private function dockerfile(?ServiceImage $image, bool $dry): void
    {
        $path = Kernel::$pathRoot . '/Dockerfile';
        if (!is_file($path)) {
            self::printWarning('No Dockerfile in the project: create one with `call cfg docker` first.');
            return;
        }
        $before = (string) file_get_contents($path);
        $after = self::withBlock($before, $image?->dockerfile());
        if ($dry) {
            self::printLabel('Dockerfile', 34);
            self::printSplit($image?->dockerfile() ?? '(no block: no services are declared)', 36);
            self::printLabel('Dockerfile', 34);
            return;
        }
        if ($after === $before) {
            self::printBadge('Dockerfile', 'UNCHANGED', 34, 36);
            return;
        }
        file_put_contents($path, $after);
        self::printBadge('Dockerfile', $image === null ? 'BLOCK REMOVED' : 'UPDATED', 34, 32);
    }

    /**
     * The Dockerfile with the services' block in place of the old one — or, the first
     * time, right before the first COPY of the last stage (the code comes after what the
     * image installs), else before ENTRYPOINT/CMD, else at the end. Null removes it.
     *
     * @throws \RuntimeException A start mark without its end: the file was edited by hand.
     */
    public static function withBlock(string $dockerfile, ?string $block): string
    {
        $lines = explode("\n", $dockerfile);
        $begin = $end = null;
        foreach ($lines as $i => $line) {
            $opens = str_starts_with($line, ServiceImage::BEGIN) && !str_starts_with($line, ServiceImage::END);
            if ($begin === null && $opens) {
                $begin = $i;
            } elseif ($begin !== null && trim($line) === ServiceImage::END) {
                $end = $i;
                break;
            }
        }
        if ($begin !== null && $end === null) {
            throw new \RuntimeException('The Dockerfile has "' . ServiceImage::BEGIN . '" without "'
                . ServiceImage::END . '": put the end mark back, or remove the block, and run again.');
        }
        $new = $block === null ? [] : explode("\n", rtrim($block, "\n"));
        if ($begin !== null) {
            // A blank line left after a removed block goes with it.
            $length = $end - $begin + 1;
            if ($block === null && ($lines[$end + 1] ?? null) === '') {
                $length++;
            }
            array_splice($lines, $begin, $length, $new);
            return implode("\n", $lines);
        }
        if ($block === null) {
            return $dockerfile;
        }
        $at = self::insertionPoint($lines);
        array_splice($lines, $at, 0, [...$new, '']);
        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private static function insertionPoint(array $lines): int
    {
        $stage = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*FROM\s/i', $line) === 1) {
                $stage = $i + 1;
            }
        }
        foreach (['/^\s*COPY\s/i', '/^\s*(ENTRYPOINT|CMD)\s/i'] as $pattern) {
            for ($i = $stage, $n = count($lines); $i < $n; $i++) {
                if (preg_match($pattern, $lines[$i]) === 1) {
                    // The comments right above it describe it: the block goes above them.
                    while ($i > $stage && str_starts_with(ltrim($lines[$i - 1]), '#')) {
                        $i--;
                    }
                    return $i;
                }
            }
        }
        return count($lines);
    }

    /**
     * `docker-compose.override.yml`: the volumes that keep the services' data and the
     * time a stop needs. Compose reads it beside `docker-compose.yml`; a file of that
     * name the command did not write is left alone, and what to add is printed instead.
     *
     * @param array<string, string> $volumes
     */
    private function override(array $volumes, bool $dry): void
    {
        $compose = Kernel::$pathRoot . '/docker-compose.yml';
        $path = Kernel::$pathRoot . '/docker-compose.override.yml';
        $ours = !is_file($path) || str_starts_with((string) file_get_contents($path), self::OVERRIDE_MARK);
        $content = $volumes === []
            ? null
            : self::overrideContent($this->serviceName($compose), $volumes, $this->stopTime());

        if ($dry || !$ours) {
            if (!$ours && $content !== null) {
                self::printWarning('docker-compose.override.yml is not this command\'s: it is left alone. Add to it:');
            }
            if ($content !== null) {
                self::printLabel('docker-compose.override.yml', 34);
                self::printSplit($content, 36);
                self::printLabel('docker-compose.override.yml', 34);
            }
            return;
        }
        if ($content === null) {
            if (is_file($path)) {
                unlink($path);
                self::printBadge('docker-compose.override.yml', 'REMOVED', 34, 32);
            }
            return;
        }
        $before = is_file($path) ? (string) file_get_contents($path) : null;
        if ($before === $content) {
            self::printBadge('docker-compose.override.yml', 'UNCHANGED', 34, 36);
            return;
        }
        file_put_contents($path, $content);
        self::printBadge('docker-compose.override.yml', $before === null ? 'CREATED' : 'UPDATED', 34, 32);
    }

    /**
     * @param array<string, string> $volumes
     */
    public static function overrideContent(string $service, array $volumes, ?int $stopGrace): string
    {
        $lines = [
            self::OVERRIDE_MARK . ' — the services the application runs in its container:',
            '# their volumes and the time a stop needs. Run it again after changing them.',
            'services:',
            "  {$service}:",
        ];
        if ($stopGrace !== null) {
            $lines[] = "    stop_grace_period: {$stopGrace}s";
        }
        $lines[] = '    volumes:';
        foreach ($volumes as $name => $mount) {
            $lines[] = "      - {$name}:{$mount}";
        }
        $lines[] = 'volumes:';
        foreach (array_keys($volumes) as $name) {
            $lines[] = "  {$name}:";
        }
        return implode("\n", $lines) . "\n";
    }

    private function stopTime(): ?int
    {
        $app = WinterApplication::getAppClass();
        $budget = $app::stopTime();
        if ($budget === null) {
            self::printWarning('A component waits for good ($grace = 0): no stop_grace_period is set.');
            return null;
        }
        // The container waits 5 seconds past the application, as the application past its components.
        return (int) ceil($budget) + 5;
    }

    /** The compose service the application is: `--service=`, else the first one in the file, else `server`. */
    private function serviceName(string $compose): string
    {
        $option = $this->args['options']['service'] ?? null;
        if (is_string($option) && $option !== '') {
            return $option;
        }
        if (is_file($compose)) {
            $inServices = false;
            foreach (explode("\n", (string) file_get_contents($compose)) as $line) {
                if (preg_match('/^services:\s*$/', $line) === 1) {
                    $inServices = true;
                    continue;
                }
                if ($inServices && preg_match('/^  ([A-Za-z0-9_.-]+):\s*$/', $line, $m) === 1) {
                    return $m[1];
                }
                if ($inServices && preg_match('/^\S/', $line) === 1) {
                    break;
                }
            }
        }
        return 'server';
    }

    // ── shared ────────────────────────────────────────────────────────────────

    /** @return list<AppService> */
    private function services(): array
    {
        $app = WinterApplication::getAppClass();
        return $app::services();
    }

    /** The Composer package a service class comes from, with its version. */
    private static function packageOf(string $class): string
    {
        $file = realpath((string) (new \ReflectionClass($class))->getFileName());
        if ($file !== false && class_exists(InstalledVersions::class)) {
            foreach (InstalledVersions::getInstalledPackages() as $package) {
                $root = InstalledVersions::getInstallPath($package);
                $root = $root !== null ? realpath($root) : false;
                if ($root !== false && str_starts_with($file, $root . '/')) {
                    return $package . ' ' . (InstalledVersions::getPrettyVersion($package) ?? '');
                }
            }
        }
        return 'the project';
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Service Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call service <command> --[options]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('list', 'the services components() declares, and what each needs', $cl, 36);
        self::printBadge('docker', 'write what they need into Dockerfile and docker-compose.override.yml', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("docker", $cl);
        self::print("Dockerfile: one block between '# winter:services' marks — apk packages,", $cl);
        self::print("directories owned by winter, environment; replaced on every run.", $cl);
        self::print("docker-compose.override.yml: the volumes and stop_grace_period;", $cl);
        self::print("docker-compose.yml itself is never edited.", $cl);
        self::print("--dry            show what would be written, write nothing", $cl);
        self::print("--service=NAME   the compose service the application is (the first one by default)", $cl);
        self::printLabel("docker", $cl);

        self::printDivider($cl);
        self::printLabel("Examples", $cl);
        self::printInfo("call service list");
        self::printInfo("call service docker --dry");
        self::printInfo("call service docker");
        self::printLabel("Examples", $cl);

        self::printTitle("Service Help", $cl);
    }
}
