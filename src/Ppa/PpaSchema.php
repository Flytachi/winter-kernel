<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Ppa\DeclarationItem;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\Ppa\Schema\SchemaDirectory;
use Flytachi\Winter\Ppa\Schema\SchemaException;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Ppa\Schema\SchemaMigrator;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Psr\Log\LoggerInterface;

/**
 * The application's databases with a schema history — every `#[Migratable]` config, in its
 * priority order, with its folder `ppa/<Config>/`: the config's short class name as it is
 * (`App\Config\MainDb` → `ppa/MainDb/`). Everything of a database goes inside its folder —
 * the code of its versions in `custom/` — so `ppa/` holds nothing but database folders.
 *
 * `call db snapshot` / `status` / `migrate` go through it, and `#[EnablePpaMigration]`
 * applies every pending version with it when the application starts.
 *
 * @link https://winterframe.net/docs/migrations Schema versions
 */
final class PpaSchema
{
    public const string DIR = 'ppa';

    private function __construct()
    {
    }

    /**
     * @return list<array{name: string, config: DbConfigInterface, driver: string, tables: list<Table>,
     *     extensions: list<\Flytachi\Winter\Ppa\Mapping\Structure\Extension>, dir: SchemaDirectory}>
     * @throws SchemaException Two configs that would share a folder.
     */
    public static function databases(?string $rootDir = null): array
    {
        DepSupport::demand(Dep::Ppa, 'Schema versions');
        $root = $rootDir ?? Kernel::$pathRoot;
        $items = array_values(array_filter(
            PPAMapping::scanningDeclaration($root)->getItems(),
            static fn(DeclarationItem $i): bool => $i->isMigratable(),
        ));
        usort($items, static fn(DeclarationItem $a, DeclarationItem $b): int
            => $a->getPriority()->value <=> $b->getPriority()->value);

        $dbs = [];
        $seen = [];
        foreach ($items as $item) {
            $class = $item->config::class;
            $name = self::nameOf($class);
            // Case aside: on macOS and Windows MainDb/ and Maindb/ are one folder.
            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new SchemaException(
                    "{$seen[$key]} and {$class} would both keep their history in "
                    . self::DIR . "/{$name}/: rename one."
                );
            }
            $seen[$key] = $class;
            $item->config->setUp();
            $dbs[] = [
                'name' => $name,
                'config' => $item->config,
                'driver' => $item->config->getDriver(),
                'tables' => $item->getTables(),
                'extensions' => $item->getExtensions(),
                'dir' => new SchemaDirectory(rtrim($root, '/') . '/' . self::DIR . '/' . $name),
            ];
        }
        return $dbs;
    }

    /** The folder of a config: its short class name as it is — `App\Config\MainDb` → `MainDb`. */
    public static function nameOf(string $configClass): string
    {
        return substr($configClass, (int) strrpos('\\' . $configClass, '\\'));
    }

    /**
     * Folders of `ppa/` with versions that belong to no database — most often a config
     * renamed without its folder.
     *
     * @param list<array{name: string}> $dbs {@see databases()}
     * @return list<string> Their names.
     */
    public static function strays(array $dbs, ?string $rootDir = null): array
    {
        $known = array_map(static fn(array $db): string => strtolower($db['name']), $dbs);
        $base = rtrim($rootDir ?? Kernel::$pathRoot, '/') . '/' . self::DIR;
        $strays = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!in_array(strtolower(basename($dir)), $known, true) && (glob($dir . '/V*.json') ?: []) !== []) {
                $strays[] = basename($dir);
            }
        }
        return $strays;
    }

    /**
     * Where the start says what the migrations did: the console's lines when the output is
     * a terminal (`call run`, `call run dev` by hand), entries of the `ppa` channel
     * when it is not (a container, a service manager) — no colours and frames in a log,
     * and `info` even with no `LOG_LEVEL`.
     */
    public static function startLogger(): LoggerInterface
    {
        return function_exists('posix_isatty') && @posix_isatty(STDOUT)
            ? new ConsoleLogger()
            : LoggerFactory::getLogger(self::class, 'ppa');
    }

    /**
     * Databases whose entities moved on from their last version — a snapshot forgotten:
     * the code expects a schema the versions do not make. Read from the files and the
     * entities only, no database.
     *
     * @param list<array> $dbs {@see databases()}, those with a history.
     * @return list<string> One line per database.
     */
    public static function drift(array $dbs): array
    {
        $lines = [];
        foreach ($dbs as $db) {
            $short = self::nameOf($db['config']::class);
            try {
                $versions = $db['dir']->versions($db['driver']);
                $diff = SchemaSnapshot::diff($db['dir'], $db['tables'], $db['driver'], extensions: $db['extensions']);
            } catch (\Throwable $e) {
                $lines[] = "{$short}: its schema versions cannot be read — {$e->getMessage()}";
                continue;
            }
            if ($diff->isEmpty() && $diff->errors === []) {
                continue;
            }
            $last = $versions === [] ? 'no version' : end($versions)->version;
            $todo = $diff->errors === [] ? '' : ', ' . count($diff->errors) . ' to decide';
            $lines[] = "{$short}: the entities differ from {$last} (" . count($diff->changes)
                . " change(s){$todo}) — record them with `call db snapshot`.";
        }
        return $lines;
    }

    public static function migrator(array $db, LoggerInterface $logger): SchemaMigrator
    {
        /** @var DbConfigInterface $config */
        $config = $db['config'];
        return new SchemaMigrator(
            $config->connection(),
            $db['driver'],
            $db['dir'],
            // The history's owner: one database may hold several (two configs, a package).
            $config::class,
            $config->getSchema(),
            $logger,
            substr($config::class, (int) strrpos('\\' . $config::class, '\\')),
        );
    }

    /**
     * Applies every pending version of every database with a history — what
     * `#[EnablePpaMigration]` runs at start. A database without a history yet is said, not
     * touched.
     *
     * @throws SchemaException The first database whose versions could not be applied.
     */
    public static function migrateAll(LoggerInterface $logger): void
    {
        $any = false;
        foreach (self::databases() as $db) {
            if (!$db['dir']->exists()) {
                $logger->warning(
                    substr($db['config']::class, (int) strrpos('\\' . $db['config']::class, '\\'))
                    . ': no schema history (' . self::DIR . "/{$db['name']}/) — run `call db snapshot` to start one"
                );
                continue;
            }
            $any = true;
            // No limit on waiting for another instance's run: failing the start after 300 s
            // only restarted it to wait again, later each time (CrashLoopBackOff). The lock
            // dies with its holder's connection; `call db migrate` keeps its limit.
            self::migrator($db, $logger)->migrate(lockTimeout: 0);
            $db['config']->disconnect();
        }
        if (!$any) {
            $logger->warning('#[EnablePpaMigration]: no database has a schema history yet — nothing was migrated');
        }
    }
}
