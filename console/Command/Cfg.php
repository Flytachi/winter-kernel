<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

final class Cfg extends Cmd
{
    public static string $title = "manage project configuration, environment and keys";
    private string $templatePath;

    public function handle(): void
    {
        self::printTitle("Config", 34);
        $this->templatePath = dirname(__DIR__) . '/Template';

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Config", 34);
    }

    private function resolution(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            switch ($this->args['arguments'][1]) {
                case 'init':
                    $this->initArg();
                    break;
                case 'key':
                    $this->keyArg();
                    break;
                case 'env':
                    $this->envArg();
                    break;
                case 'docker':
                    $this->dockerArg();
                    break;
                case 'completion':
                    $this->completionArg();
                    break;
                default:
                    self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                    self::printInfo("Run 'call cfg --help' to see available commands.");
                    break;
            }
        }
    }

    private function initArg(): void
    {
        $filePath = Kernel::$pathRoot . '/composer.json';
        if (file_exists($filePath) && is_readable($filePath)) {
            $projectData = json_decode(
                file_get_contents($filePath) ?: '',
                true
            );

            if (isset($projectData['name'])) {
                $projectData['name'] = 'project/' . basename(Kernel::$pathRoot);
            }
            if (isset($projectData['description'])) {
                $projectData['description'] = 'My Project ' . basename(Kernel::$pathRoot);
            }
            if (isset($projectData['keywords'])) {
                unset($projectData['keywords']);
            }
            if (isset($projectData['scripts'])) {
                unset($projectData['scripts']);
            }
            if (isset($projectData['authors'])) {
                $projectData['authors'] = [];
            }

            $json = json_encode($projectData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents($filePath, $json . PHP_EOL);
        }

        $this->envCreate();
        $this->keyGenerate();
    }

    private function keyArg(): void
    {
        if (in_array('g', $this->args['flags'])) {
            $this->keyGenerate();
        }
        if (in_array('s', $this->args['flags'])) {
            $this->keyShow();
        }
    }

    private function keyGenerate(): void
    {
        $envPath = Kernel::$pathEnv;
        if (!file_exists($envPath)) {
            self::printWarning('.env file not found. Run: call cfg env -i');
            return;
        }

        $newKey  = bin2hex(random_bytes(32));
        $content = file_get_contents($envPath);

        if (preg_match('/^WINTER_KEY=(.*)$/m', $content, $match)) {
            $oldKey  = $match[1];
            $content = preg_replace('/^WINTER_KEY=.*$/m', 'WINTER_KEY=' . $newKey, $content);
            self::printKeyValue('Old key', $oldKey ?: '(empty)', 10, 34, 90);
        } else {
            $content .= PHP_EOL . 'WINTER_KEY=' . $newKey;
        }

        file_put_contents($envPath, $content);
        self::printSuccess('WINTER_KEY generated and saved to .env');
        self::printKeyValue('New key', $newKey, 10, 34, 32);
    }

    private function keyShow(): void
    {
        $key = env('WINTER_KEY', '');
        self::printLabel('WINTER_KEY', 34);
        if ($key) {
            self::printKeyValue('Key', $key, 6, 34, 36);
        } else {
            self::printWarning('WINTER_KEY is not set.');
            self::printInfo("Generate one with: call cfg key -g");
        }
        self::printLabel('WINTER_KEY', 34);
    }

    private function envArg(): void
    {
        if (in_array('i', $this->args['flags'])) {
            $this->envCreate();
        }
        if (in_array('s', $this->args['flags'])) {
            $this->envShow();
        }
    }

    private function envCreate(): void
    {
        if (!file_exists(Kernel::$pathEnv)) {
            if (copy($this->templatePath . '/Build/env', Kernel::$pathEnv)) {
                self::printBadge('.env', 'CREATED', 34, 32);
            } else {
                self::printBadge('.env', 'FAILED', 34, 31);
            }
        } else {
            self::printBadge('.env', 'EXISTS', 34, 33);
        }

        $this->phpstormMetta();
    }

    private function phpstormMetta(): void
    {
        try {
            if (is_dir(Kernel::$pathRoot . '/vendor')) {
                $metaPath = Kernel::$pathRoot . '/vendor/.phpstorm.meta';
                if (!is_dir($metaPath)) {
                    mkdir($metaPath, 0777, true);
                }
                $metaPath = $metaPath . '/.phpstorm.meta.php';
                if (!file_exists($metaPath)) {
                    copy(
                        $this->templatePath . '/Build/phpstormMeta',
                        $metaPath
                    );
                }
            }
        } catch (\Throwable) {
        }
    }

    private function envShow(): void
    {
        if (in_array('file', $this->args['options'])) {
            if (is_file(Kernel::$pathEnv)) {
                self::printLabel(Kernel::$pathEnv, 34);
                self::printSplit(file_get_contents(Kernel::$pathEnv), 34);
                self::printLabel(Kernel::$pathEnv, 34);
            } else {
                self::printWarning("File '" . Kernel::$pathEnv . "' does not exist.");
                self::printInfo("Create it with: call cfg env -i");
            }
        } else {
            self::printLabel('ENVIRONMENT', 34);
            foreach ($_ENV as $key => $value) {
                self::printKeyValue($key, $value, 28, 34, 36);
            }
            self::printLabel('ENVIRONMENT', 34);
        }
    }

    /**
     * Copies the Docker template into the project: Dockerfile, docker-compose.yml,
     * .dockerignore and docker/ (entrypoint.sh, php-*.ini, dependencies/*.sh).
     *
     * A file that is already there is left alone — it is likely edited, and running the
     * command again must not wipe that out. `-f` overwrites. Every file says which it was.
     */
    private function dockerArg(): void
    {
        $force = in_array('f', $this->args['flags']);
        $source = $this->templatePath . '/Docker';
        $skipped = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];
        foreach ($files as $file) {
            $paths[] = substr($file->getPathname(), strlen($source) + 1);
        }
        sort($paths);

        foreach ($paths as $relative) {
            $target = Kernel::$pathRoot . '/' . $relative;
            $exists = is_file($target);
            if ($exists && !$force) {
                self::printBadge($relative, 'EXISTS', 34, 33);
                $skipped++;
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            $copied = @copy($source . '/' . $relative, $target);
            if ($copied && str_ends_with($relative, '.sh')) {
                @chmod($target, 0755);
            }
            self::printBadge(
                $relative,
                !$copied ? 'FAILED' : ($exists ? 'OVERWRITTEN' : 'CREATED'),
                34,
                !$copied ? 31 : 32,
            );
        }

        if ($skipped > 0) {
            self::printInfo("{$skipped} file(s) already there were kept. `call cfg docker -f` overwrites them.");
        }
        self::printInfo("Run:  docker compose up  (prod)  |  DEV=true docker compose up  (dev, hot-reload)");
        self::printInfo("DB drivers / extensions: keep or remove docker/dependencies/*.sh");
    }

    private function completionArg(): void
    {
        $home  = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        $shell = $_SERVER['SHELL'] ?? getenv('SHELL') ?: '';
        $force = in_array('f', $this->args['flags']);

        if (in_array('i', $this->args['flags'])) {
            if (!$home) {
                self::printWarning('Cannot detect HOME directory.');
                self::printInfo('Add the script below manually to your shell config:');
                echo file_get_contents($this->templatePath . '/Build/completion');
                return;
            }

            if (str_contains($shell, 'zsh')) {
                $this->installCompletionZsh($home, $force);
            } else {
                $this->installCompletionBash($home, $force);
            }
        } else {
            echo file_get_contents($this->templatePath . '/Build/completion');
        }
    }

    private function installCompletionZsh(string $home, bool $force): void
    {
        $completionDir  = $home . '/.zsh/completions';
        $completionFile = $completionDir . '/_call';
        $rcFile         = $home . '/.zshrc';
        $fpathMarker    = '# winter-fpath';
        $fpathLine      = 'fpath=(' . $completionDir . ' $fpath)';

        if (!is_dir($completionDir)) {
            mkdir($completionDir, 0755, true);
        }

        // Write _call completion file
        if (!file_exists($completionFile) || $force) {
            file_put_contents($completionFile, file_get_contents($this->templatePath . '/Build/completion_zsh'));
            self::printBadge('_call', $force ? 'UPDATED' : 'INSTALLED', 34, 32);
        } else {
            self::printBadge('_call', 'EXISTS', 34, 33);
            self::printInfo("To update: call cfg completion -if");
        }
        self::printKeyValue('file', $completionFile, 6, 34, 90);

        // Prepend fpath to ~/.zshrc so it's set before compinit (oh-my-zsh, etc.)
        $rcContent = file_exists($rcFile) ? file_get_contents($rcFile) : '';
        if (!str_contains($rcContent, $fpathMarker)) {
            file_put_contents($rcFile, $fpathMarker . PHP_EOL . $fpathLine . PHP_EOL . PHP_EOL . $rcContent);
            self::printBadge('fpath', 'ADDED', 34, 32);
            self::printKeyValue('rc', $rcFile, 4, 34, 90);
        }

        self::printSuccess("Run: exec zsh");
    }

    private function installCompletionBash(string $home, bool $force): void
    {
        $completionDir  = $home . '/.bash_completion.d';
        $completionFile = $completionDir . '/call';
        $rcFile         = $home . '/.bashrc';
        $marker         = '# winter-bash-completion';
        $sourceLine     = '[[ -d ~/.bash_completion.d ]] && for f in ~/.bash_completion.d/*; do source "$f"; done';

        if (!is_dir($completionDir)) {
            mkdir($completionDir, 0755, true);
        }

        if (!file_exists($completionFile) || $force) {
            file_put_contents($completionFile, file_get_contents($this->templatePath . '/Build/completion_bash'));
            self::printBadge('call', $force ? 'UPDATED' : 'INSTALLED', 34, 32);
        } else {
            self::printBadge('call', 'EXISTS', 34, 33);
            self::printInfo("To update: call cfg completion -if");
        }
        self::printKeyValue('file', $completionFile, 6, 34, 90);

        $rcContent = file_exists($rcFile) ? file_get_contents($rcFile) : '';
        if (!str_contains($rcContent, $marker)) {
            file_put_contents($rcFile, PHP_EOL . $marker . PHP_EOL . $sourceLine . PHP_EOL, FILE_APPEND);
            self::printBadge('source', 'ADDED', 34, 32);
            self::printKeyValue('rc', $rcFile, 4, 34, 90);
        }

        self::printSuccess("Run: source $rcFile");
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Config Help", $cl);

        // usage
        self::printLabel("Usage", $cl);
        self::print("call cfg <command> -[flags] --[options]", $cl);
        self::printLabel("Usage", $cl);

        // commands overview
        self::printLabel("Commands", $cl);
        self::printBadge('init', 'initialize project: composer.json extras + .env', $cl, 36);
        self::printBadge('key', 'manage WINTER_KEY (project security key)', $cl, 36);
        self::printBadge('env', 'manage .env environment file', $cl, 36);
        self::printBadge('docker', 'scaffold Docker configuration files (Swoole)', $cl, 36);
        self::printBadge('completion', 'install shell tab completion (bash/zsh)', $cl, 36);
        self::printLabel("Commands", $cl);

        // key
        self::printDivider($cl);
        self::printLabel("key — WINTER_KEY management", $cl);
        self::print("-g   generate (or regenerate) WINTER_KEY and save to .env", $cl);
        self::print("-s   show current WINTER_KEY value", $cl);
        self::printLabel("key — WINTER_KEY management", $cl);

        // env
        self::printLabel("env — environment file", $cl);
        self::print("-i           create .env from template (if not exists)", $cl);
        self::print("-s           show loaded environment variables", $cl);
        self::print("-s --file    show raw .env file contents", $cl);
        self::printLabel("env — environment file", $cl);

        // docker
        self::printLabel("docker — scaffold Docker files", $cl);
        self::print("Swoole image. dev vs prod = DEV in docker-compose.yml:", $cl);
        self::print("  docker compose up            prod (opcache on, no watcher)", $cl);
        self::print("  DEV=true docker compose up   dev (hot-reload, opcache off)", $cl);
        self::print("DB drivers / extensions / cron → docker/dependencies/*.sh", $cl);
        self::print("  (keep or remove; bcmath, pgsql, mysql, redis shipped)", $cl);
        self::print("Files already there are kept; -f overwrites them.", $cl);
        self::printLabel("docker — scaffold Docker files", $cl);

        // examples
        self::printDivider($cl);
        self::printLabel("Examples", $cl);
        self::printInfo("call cfg init");
        self::printInfo("call cfg key -g");
        self::printInfo("call cfg key -s");
        self::printInfo("call cfg env -i");
        self::printInfo("call cfg env -s");
        self::printInfo("call cfg env -s --file");
        self::printInfo("call cfg docker           (scaffold Docker files)");
        self::printInfo("call cfg completion        (print scripts to stdout)");
        self::printInfo("call cfg completion -i     (install: ~/.zsh/completions/_call or ~/.bash_completion.d/call)");
        self::printInfo("call cfg completion -if    (force update installed file)");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-cfg");

        self::printTitle("Config Help", $cl);
    }
}
