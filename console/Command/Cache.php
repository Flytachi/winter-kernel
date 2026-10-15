<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\App\Attribute\EnableCaching;
use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheManager;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Cache\Store\ListableStore;
use Flytachi\Winter\Kernel\WinterApplication;
use ReflectionClass;

/**
 * Inspects and clears the caches declared in CacheConfigurer.
 *
 * A console command is a process of its own, so it sees only the caches that live
 * outside the server: file and Redis. A table or a memory cache is shown with its
 * settings, but its entries are in the running server's memory, out of reach.
 */
final class Cache extends Cmd
{
    public static string $title = "inspect and clear method caches (show, clear)";

    /** Entries listed by `show <name>` unless `--all` is given. */
    private const int SHOW_LIMIT = 100;

    public function handle(): void
    {
        self::printTitle("Cache", 34);

        $sub = $this->args['arguments'][1] ?? '';
        $name = $this->args['arguments'][2] ?? null;
        $all = array_key_exists('all', $this->args['options']);

        $ok = match (true) {
            $sub === '' => self::helpAndSucceed(),
            !self::cachingEnabled() => self::notEnabled(),
            $sub === 'show' && $name === null => $this->showAll(),
            $sub === 'show' => $this->showOne($name, $all),
            $sub === 'clear' && $all => $this->clearAll(),
            $sub === 'clear' && $name !== null => $this->clearOne($name),
            $sub === 'clear' => self::clearNeedsTarget(),
            default => self::unknown($sub),
        };

        self::printTitle("Cache", 34);

        // A clear that did not happen must be visible to a deploy script.
        if (!$ok) {
            exit(1);
        }
    }

    // ── show ─────────────────────────────────────────────────────────────────

    private function showAll(): bool
    {
        $definitions = CacheManager::definitions();
        if ($definitions === []) {
            self::printWarning('No caches declared: add a class implementing CacheConfigurer.');

            return true;
        }

        if (!CacheManager::isEnabled()) {
            self::printWarning('CACHE_ENABLED=false — the server runs every cached method without its cache.');
        }

        $ok = true;
        $label = 'Caches (' . count($definitions) . ')';
        self::printLabel($label, 34);
        foreach ($definitions as $definition) {
            [$summary, $reachable] = self::summary($definition);
            self::printBadge($definition->name, $summary, 36, $reachable ? 32 : 31);
            $ok = $ok && $reachable;
        }
        self::printLabel($label, 34);

        self::printInfo('table and memory caches live in the server\'s memory: their entries are not visible here.');
        self::printInfo('call cache show <name> lists the entries of a file or redis cache.');

        return $ok;
    }

    private function showOne(string $name, bool $all): bool
    {
        $definition = self::definition($name);
        if ($definition === null) {
            return false;
        }

        self::printBadge($definition->name, self::settings($definition), 36, 32);

        if (!$definition->type->isPersistent()) {
            self::printInfo(
                "A {$definition->type->value} cache lives in the memory of the running server; "
                . 'a console command cannot see its entries.'
            );

            return true;
        }

        try {
            /** @var ListableStore $store */
            $store = CacheManager::store($name);
            $total = $store->count();
            $entries = $store->entries($all ? null : self::SHOW_LIMIT);
        } catch (\Throwable $e) {
            self::printWarning("Cache '{$name}' is unavailable: " . $e->getMessage());

            return false;
        }

        self::printInfo($store->location());

        if ($entries === []) {
            self::printInfo('No entries.');

            return true;
        }

        $label = "Entries ({$total})";
        self::printLabel($label, 34);
        foreach ($entries as $entry) {
            self::printBadge(
                self::readableKey($entry['key']),
                self::bytes($entry['bytes']) . ' · ' . ($entry['expiresIn'] === null
                    ? 'never expires'
                    : $entry['expiresIn'] . ' s left'),
                36,
                32
            );
        }
        self::printLabel($label, 34);

        if (count($entries) < $total) {
            self::printInfo('Showing ' . count($entries) . " of {$total}; add --all for every entry.");
        }

        return true;
    }

    // ── clear ────────────────────────────────────────────────────────────────

    private function clearOne(string $name): bool
    {
        $definition = self::definition($name);
        if ($definition === null) {
            return false;
        }

        if (!$definition->type->isPersistent()) {
            self::printWarning(
                "Cache '{$name}' is a {$definition->type->value} cache: it lives in the memory of the running "
                . 'server and is emptied when the server restarts. A console command cannot reach it.'
            );

            return false;
        }

        return self::clear($definition);
    }

    private function clearAll(): bool
    {
        $ok = true;
        $cleared = 0;
        foreach (CacheManager::definitions() as $definition) {
            if ($definition->type->isPersistent()) {
                $ok = self::clear($definition) && $ok;
                $cleared++;
            } else {
                self::printBadge($definition->name, "SKIPPED ({$definition->type->value}, server memory)", 36, 33);
            }
        }

        if ($cleared === 0) {
            self::printInfo('No file or redis caches declared — nothing a console command can clear.');
        }

        return $ok;
    }

    private static function clear(CacheDefinition $definition): bool
    {
        try {
            $store = CacheManager::store($definition->name);
            $count = $store->count();
            $store->clear();
        } catch (\Throwable $e) {
            self::printBadge($definition->name, 'FAILED', 36, 31);
            self::printWarning($e->getMessage());

            return false;
        }

        self::printBadge($definition->name, "CLEARED ({$count} entries)", 36, 32);

        return true;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Mirrors the boot decision: caching exists only with #[EnableCaching] on the app
     * class. A non-WinterApplication entry sets no app class and is taken at its word.
     */
    private static function cachingEnabled(): bool
    {
        $app = WinterApplication::getAppClass();

        return $app === '' || new ReflectionClass($app)->getAttributes(EnableCaching::class) !== [];
    }

    private static function definition(string $name): ?CacheDefinition
    {
        $definition = CacheManager::definitions()[$name] ?? null;
        if ($definition === null) {
            $known = array_keys(CacheManager::definitions());
            self::printWarning("No cache named '{$name}'." . ($known === []
                ? ''
                : ' Declared: ' . implode(', ', $known) . '.'));
        }

        return $definition;
    }

    /**
     * @return array{string, bool} The badge text, and whether the store answered.
     */
    private static function summary(CacheDefinition $definition): array
    {
        $settings = self::settings($definition);
        if (!$definition->type->isPersistent()) {
            return [$settings, true];
        }

        try {
            $count = CacheManager::store($definition->name)->count();
        } catch (\Throwable) {
            return [$settings . ' · UNAVAILABLE', false];
        }

        return [$settings . ' · ' . $count . ' ' . ($count === 1 ? 'entry' : 'entries'), true];
    }

    private static function settings(CacheDefinition $definition): string
    {
        $parts = [$definition->type->value];
        $parts = match ($definition->type) {
            CacheType::Table => [
                ...$parts,
                $definition->size . ' rows',
                self::bytes((int) $definition->valueSize),
                '≈' . self::bytes($definition->reservedBytes()),
            ],
            CacheType::Memory => [...$parts, $definition->size . ' items per worker'],
            CacheType::File, CacheType::Redis => $parts,
        };

        $parts[] = $definition->ttl ? 'ttl ' . $definition->ttl . ' s' : 'no ttl';
        if ($definition->version !== null) {
            $parts[] = 'version ' . $definition->version;
        }

        return implode(' · ', $parts);
    }

    private static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024, 1) . ' KB',
            default => $bytes . ' B',
        };
    }

    /**
     * A key built from several arguments joins them with an invisible separator and
     * spells null as a NUL byte; both are made visible here.
     */
    private static function readableKey(string $key): string
    {
        return strtr($key, ["\x1F" => ', ', "\0" => 'null']);
    }

    private static function helpAndSucceed(): bool
    {
        self::help();

        return true;
    }

    private static function notEnabled(): bool
    {
        self::printWarning('Caching is not enabled: the application class declares no #[EnableCaching].');

        return false;
    }

    private static function clearNeedsTarget(): bool
    {
        self::printWarning('Name the cache to clear, or pass --all: call cache clear <name> | --all');

        return false;
    }

    private static function unknown(string $sub): bool
    {
        self::printWarning("Unknown action '{$sub}'. Run 'call cache --help' to see the actions.");

        return false;
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Cache Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call cache <action> [name] [--all]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Actions", $cl);
        self::printBadge('show', 'every declared cache: store, settings, entry count', $cl, 36);
        self::printBadge('show <name>', 'entries of a file or redis cache: key, size, time left', $cl, 36);
        self::printBadge('show <name> --all', 'every entry instead of the first ' . self::SHOW_LIMIT, $cl, 36);
        self::printBadge('clear <name>', 'empty one file or redis cache', $cl, 36);
        self::printBadge('clear --all', 'empty every file and redis cache', $cl, 36);
        self::printLabel("Actions", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call cache show");
        self::printInfo("call cache show exchange-token");
        self::printInfo("call cache clear products");
        self::printInfo("call cache clear --all     # e.g. in a deploy script");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("table and memory caches live in the running server's memory: listed, never cleared here.");
        self::printInfo("A clear that fails — an unknown name, an unreachable Redis — exits with code 1.");

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-cache");

        self::printTitle("Cache Help", $cl);
    }
}
