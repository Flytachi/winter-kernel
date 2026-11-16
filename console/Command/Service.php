<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\InstalledVersions;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\App\Service as AppService;
use Flytachi\Winter\Kernel\App\FrontBuild;
use Flytachi\Winter\Kernel\App\ServiceImage;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * The services the application runs inside its container — a database, a cache — as
 * {@see \Flytachi\Winter\Kernel\App\Components::service()} declares them.
 *
 * `list` shows them; `docker` writes what the image needs for them into the Dockerfile
 * (a block between marks, replaced on every run) and prints what compose needs — the
 * stop time and the volumes for their data. Compose is the developer's: it is never
 * written.
 */
final class Service extends Cmd
{
    public static string $title = "the services the application runs in its container: list | docker";

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

        $builds = [];
        $app = WinterApplication::getAppClass();
        foreach ($app::frontends() as $frontend) {
            $build = $frontend->build();
            if ($build !== null) {
                $builds[$frontend->site()->name] = $build;
            }
        }

        $this->dockerfile($services === [] ? null : $image, $builds, $dry);
        if ($services !== []) {
            $this->composeHint($image->volumes);
        }
        if ($builds !== []) {
            self::dockerignoreHint(array_values(array_map(static fn(FrontBuild $b): string => $b->context, $builds)));
        }

        if ($dry) {
            self::printInfo('--dry: nothing was written.');
            return;
        }
        if ($services !== [] || $builds !== []) {
            self::printInfo('Rebuild the image to take it in:  docker compose up -d --build');
        }
    }

    /**
     * The blocks in the Dockerfile — the services', the frontends' build stages and their
     * output: replaced when they are there, put in place when not.
     *
     * @param array<string, FrontBuild> $builds By frontend name.
     */
    private function dockerfile(?ServiceImage $image, array $builds, bool $dry): void
    {
        $path = Kernel::$pathRoot . '/Dockerfile';
        if (!is_file($path)) {
            self::printWarning('No Dockerfile in the project: create one with `call cfg docker` first.');
            return;
        }
        $before = (string) file_get_contents($path);
        [$stages, $outputs] = self::frontendBlocks($builds);
        $after = self::withBlock($before, $image?->dockerfile());
        $after = self::withFrontendStages($after, $stages);
        $after = self::withFrontendOutputs($after, $outputs);
        if ($dry) {
            self::printLabel('Dockerfile', 34);
            self::printSplit($image?->dockerfile() ?? '(no block: no services are declared)', 36);
            if ($stages !== null) {
                self::printSplit($stages, 36);
                self::printSplit((string) $outputs, 36);
            }
            self::printLabel('Dockerfile', 34);
            return;
        }
        if ($after === $before) {
            self::printBadge('Dockerfile', 'UNCHANGED', 34, 36);
            return;
        }
        file_put_contents($path, $after);
        self::printBadge('Dockerfile', $image === null && $stages === null ? 'BLOCK REMOVED' : 'UPDATED', 34, 32);
    }

    private const string STAGES = '# winter:frontend-build';
    private const string STAGES_END = '# winter:frontend-build end';
    private const string OUTPUTS = '# winter:frontend-output';
    private const string OUTPUTS_END = '# winter:frontend-output end';

    /**
     * The frontends' blocks: a build stage each — the manifest first, so the install is
     * cached while the dependencies do not change, then the sources and the build — and
     * the lines that copy each output into the last stage. Nulls when there is no build.
     *
     * @param array<string, FrontBuild> $builds By frontend name.
     * @return array{0: ?string, 1: ?string}
     */
    public static function frontendBlocks(array $builds): array
    {
        if ($builds === []) {
            return [null, null];
        }
        $note = ' — written by `call service docker`; change the frontends, not this block';
        $stages = [self::STAGES . $note];
        $outputs = [self::OUTPUTS . $note];
        foreach ($builds as $name => $build) {
            $manifest = implode(' ', array_map(
                static fn(string $file): string => "{$build->context}/{$file}",
                $build->manifest,
            ));
            array_push(
                $stages,
                "FROM {$build->image} AS frontend-{$name}",
                'WORKDIR /front',
                'RUN corepack enable',
                "COPY {$manifest} ./",
                "RUN {$build->install}",
                "COPY {$build->context}/ ./",
                "RUN {$build->build}",
            );
            $outputs[] = "COPY --from=frontend-{$name} --chown=winter:winter /front/{$build->output}"
                . " ./{$build->context}/{$build->output}";
        }
        $stages[] = self::STAGES_END;
        $outputs[] = self::OUTPUTS_END;
        return [implode("\n", $stages) . "\n", implode("\n", $outputs) . "\n"];
    }

    /**
     * The line of the last stage's FROM — an instruction, not a word on the line after a
     * backslash that continues the one before.
     *
     * @param list<string> $lines
     */
    private static function lastFrom(array $lines): ?int
    {
        $last = null;
        $continued = false;
        foreach ($lines as $i => $line) {
            if (!$continued && preg_match('/^\s*FROM\s/i', $line) === 1) {
                $last = $i;
            }
            $continued = str_ends_with(rtrim($line), '\\') && !str_starts_with(ltrim($line), '#');
        }
        return $last;
    }

    /** The Dockerfile with the build stages before its last stage — its comments above them. */
    public static function withFrontendStages(string $dockerfile, ?string $block): string
    {
        $before = static function (array $lines): int {
            $at = self::lastFrom($lines) ?? count($lines);
            // Its comments go below the stages with it — a parser directive (`# syntax=`)
            // never: it counts only on the file's first lines.
            while (
                $at > 0
                && str_starts_with(ltrim($lines[$at - 1]), '#')
                && preg_match('/^#\s*(syntax|escape|check)\s*=/i', $lines[$at - 1]) !== 1
            ) {
                $at--;
            }
            return $at;
        };
        return self::withMarked($dockerfile, self::STAGES, self::STAGES_END, $block, $before);
    }

    /** The Dockerfile with the outputs copied in at the end of its last stage, before it starts. */
    public static function withFrontendOutputs(string $dockerfile, ?string $block): string
    {
        $atTheEnd = static function (array $lines): int {
            $from = self::lastFrom($lines);
            $stage = $from === null ? 0 : $from + 1;
            for ($i = $stage, $n = count($lines); $i < $n; $i++) {
                if (preg_match('/^\s*(ENTRYPOINT|CMD)\s/i', $lines[$i]) === 1) {
                    while ($i > $stage && str_starts_with(ltrim($lines[$i - 1]), '#')) {
                        $i--;
                    }
                    return $i;
                }
            }
            return count($lines);
        };
        return self::withMarked($dockerfile, self::OUTPUTS, self::OUTPUTS_END, $block, $atTheEnd);
    }

    /**
     * A block between two marks: replaced in place, put where `$at` says the first time,
     * removed — with the blank line after it — when null.
     *
     * @param \Closure(list<string>): int $at
     * @throws \RuntimeException A start mark without its end: the file was edited by hand.
     */
    private static function withMarked(
        string $dockerfile,
        string $begin,
        string $end,
        ?string $block,
        \Closure $at,
    ): string {
        $lines = explode("\n", $dockerfile);
        $from = $to = null;
        foreach ($lines as $i => $line) {
            if ($from === null && str_starts_with($line, $begin) && !str_starts_with($line, $end)) {
                $from = $i;
            } elseif ($from !== null && trim($line) === $end) {
                $to = $i;
                break;
            }
        }
        if ($from !== null && $to === null) {
            throw new \RuntimeException(
                "The Dockerfile has \"{$begin}\" without \"{$end}\": "
                . 'put the end mark back, or remove the block, and run again.'
            );
        }
        $new = $block === null ? [] : explode("\n", rtrim($block, "\n"));
        if ($from !== null) {
            $length = $to - $from + 1;
            if ($block === null && ($lines[$to + 1] ?? null) === '') {
                $length++;
            }
            array_splice($lines, $from, $length, $new);
            return implode("\n", $lines);
        }
        if ($block === null) {
            return $dockerfile;
        }
        array_splice($lines, $at($lines), 0, [...$new, '']);
        return implode("\n", $lines);
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
     * What compose needs for the services, printed, never written: compose is the
     * developer's — whether the data outlives the container, and where it lives, is
     * theirs to decide.
     *
     * @param array<string, string> $volumes
     */
    /**
     * What a frontend's folder holds that the build context must not carry: the host's
     * `node_modules` — another platform's binaries, hundreds of megabytes copied into the
     * image with the code — and the build folders. Said, not written: the file is the
     * developer's. Docker reads a pattern from the context's root: `node_modules` leaves
     * out the root's only; `**\/node_modules` or `frontend/node_modules` the frontend's.
     *
     * @param list<string> $contexts The frontends' folders.
     */
    private static function dockerignoreHint(array $contexts): void
    {
        $text = @file_get_contents(Kernel::$pathRoot . '/.dockerignore');
        $lines = is_string($text)
            ? array_map(static fn(string $l): string => trim(trim($l), '/'), explode("\n", $text))
            : [];
        $missing = [];
        foreach (['node_modules', '.nuxt', '.output'] as $folder) {
            if (in_array("**/{$folder}", $lines, true)) {
                continue;
            }
            foreach ($contexts as $context) {
                if (!in_array("{$context}/{$folder}", $lines, true)) {
                    $missing[] = "**/{$folder}";
                    break;
                }
            }
        }
        if ($missing !== []) {
            self::printWarning(sprintf(
                '.dockerignore does not leave out %s: the build context and the image would carry them — add %s.',
                implode(', ', $missing),
                count($missing) === 1 ? 'it' : 'them',
            ));
        }
    }

    private function composeHint(array $volumes): void
    {
        $stopGrace = $this->stopTime();
        if ($stopGrace === null && $volumes === []) {
            return;
        }
        $service = $this->serviceName(Kernel::$pathRoot . '/docker-compose.yml');
        self::printInfo("Compose is yours: add to docker-compose.yml, service \"{$service}\", what fits:");
        self::printLabel('docker-compose.yml', 34);
        self::printSplit(rtrim(self::composeSnippet($service, $volumes, $stopGrace)), 36);
        self::printLabel('docker-compose.yml', 34);
        if ($stopGrace !== null) {
            self::printInfo("stop_grace_period: a stop needs {$stopGrace}s;"
                . ' without it Docker kills the container after 10s.');
        }
        if ($volumes !== []) {
            self::printInfo('volumes: keep the data when the container is recreated. Leave them out if it need not'
                . ' survive; skip one whose path is already inside a volume of yours.');
        }
    }

    /**
     * @param array<string, string> $volumes
     */
    public static function composeSnippet(string $service, array $volumes, ?int $stopGrace): string
    {
        $lines = ['services:', "  {$service}:"];
        if ($stopGrace !== null) {
            $lines[] = "    stop_grace_period: {$stopGrace}s";
        }
        if ($volumes !== []) {
            $lines[] = '    volumes:';
            foreach ($volumes as $name => $mount) {
                $lines[] = "      - {$name}:{$mount}";
            }
            $lines[] = 'volumes:';
            foreach (array_keys($volumes) as $name) {
                $lines[] = "  {$name}:";
            }
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
        self::printBadge('docker', 'write what they need into the Dockerfile, show what compose needs', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("docker", $cl);
        self::print("Dockerfile: one block between '# winter:services' marks — apk packages,", $cl);
        self::print("directories owned by winter, environment; replaced on every run.", $cl);
        self::print("Compose is never written: stop_grace_period and the volumes it needs", $cl);
        self::print("are printed, to add to docker-compose.yml as fits.", $cl);
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
