<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Ppa\DeclarationItem;
use Flytachi\Winter\Kernel\Ppa\PPAMapping;
use RuntimeException;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use Flytachi\Winter\Kernel\App\PluginPackage;
use Flytachi\Winter\Kernel\Plugin;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Ppa\ConsoleLogger;
use Flytachi\Winter\Kernel\Ppa\PpaSchema;
use Flytachi\Winter\Ppa\Schema\RenamedFromCleaner;
use Flytachi\Winter\Ppa\Schema\SchemaCheck;
use Flytachi\Winter\Ppa\Schema\SchemaModel;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Flytachi\Winter\Ppa\Schema\SchemaSql;
use Flytachi\Winter\Ppa\Schema\SchemaVersionFile;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Db extends Cmd
{
    public static string $title = "manage database migrations and SQL preview";

    public function handle(): void
    {
        self::printTitle("Db", 34);

        if (count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Db", 34);
    }

    private function resolution(): void
    {
        // Every subcommand here reads configs, builds declarations or reports pools —
        // all of it lives in the database package, which an application without a
        // database does not install. Saying so once, here, keeps the four branches below
        // free of the question and turns a "class not found" into an instruction.
        try {
            DepSupport::demand(Dep::Ppa, "The 'db' command");
        } catch (RuntimeException $e) {
            self::printMissingDependency($e->getMessage());
            return;
        }

        if (empty($this->args['flags'])) {
            $this->args['flags'] = ['e', 's', 't', 'i', 'c'];
        }
        switch ($this->args['arguments'][1] ?? '') {
            case 'ping':
                $this->ping();
                break;
            case 'migrate':
                $this->migrate();
                break;
            case 'sql':
                $this->showSql();
                break;
            case 'pool':
                $this->pool();
                break;
            case 'snapshot':
                match ($sub = $this->args['arguments'][2] ?? '') {
                    'clean' => $this->snapshotClean(),
                    '', 'custom' => $this->snapshot(),
                    'help' => self::help(),
                    default => self::printWarning(
                        "Unknown 'db snapshot {$sub}': snapshot, snapshot custom <name>, snapshot clean."
                    ),
                };
                break;
            case 'status':
                $this->status();
                break;
            default:
                self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                self::printInfo("Run 'call db --help' to see available commands.");
                break;
        }
    }

    // --- Plugin resolution ---

    /**
     * What to scan, as label + root. A package declaring several PSR-4 roots yields one
     * entry per root, so the label repeats — which is why this is a list of pairs and
     * not a map.
     *
     * @return list<array{label: string, root: string|null}>  root null = the project
     */
    private function resolveTargets(): array
    {
        $options = $this->args['options'];

        if (isset($options['plugin'])) {
            $name = $options['plugin'];
            foreach (Plugin::all() as $plugin) {
                if ($plugin->package === $name) {
                    return self::targetsOf($plugin);
                }
            }
            self::printWarning("Plugin '$name' is not imported.");
            return [];
        }

        if (isset($options['plugins'])) {
            $plugins = Plugin::all();
            if ($plugins === []) {
                self::printWarning('No packages are imported.');
                return [];
            }
            return array_merge(...array_map(self::targetsOf(...), $plugins));
        }

        return [['label' => 'Project', 'root' => null]];
    }

    /** @return list<array{label: string, root: string}> */
    private static function targetsOf(PluginPackage $plugin): array
    {
        return array_map(
            static fn(string $root): array => ['label' => $plugin->package, 'root' => $root],
            $plugin->roots,
        );
    }

    // --- Commands ---

    private function ping(): void
    {
        $targets = array_merge(
            [['label' => 'Project', 'root' => null]],
            ...array_map(self::targetsOf(...), Plugin::all()),
        );
        foreach ($targets as ['label' => $label, 'root' => $rootDir]) {
            $configs = PPAMapping::scanningConfigs($rootDir);

            if (empty($configs)) {
                self::printWarning("[$label] No DB configs found — nothing to ping.");
                continue;
            }

            foreach ($configs as $config) {
                $configClass = $config::class;
                $config->setUp();
                $detail = $config->pingDetail();

                self::printLabel("[$label] $configClass", 34);
                self::printKeyValue("driver", $config->getDriver(), 12, 34, 36);
                self::printKeyValue("dsn", $config->getDns(), 12, 34, 36);
                if ($detail['status']) {
                    self::printKeyValue("latency", round($detail['latency'], 2) . ' ms', 12, 34, 36);
                    self::printBadge($configClass, 'OK', 34, 32);
                } else {
                    self::printBadge($configClass, 'FAILED', 34, 31);
                    if ($detail['error']) {
                        self::printInfo($detail['error']);
                    }
                }
            }
        }
    }

    /**
     * Shows connection-pool utilisation of the running application.
     *
     * A pool lives in one process's memory and the CLI is a separate process, so this
     * reads what each one publishes to the shared store ({@see PoolTelemetry}) — the
     * same indirection `call process status` uses. Numbers are therefore as fresh as
     * the last publish (see the `age` column), and a stopped source's record simply
     * expires.
     *
     * Web workers and processes are shown apart: every process and daemon worker keeps
     * a pool of its own, so a saturated one stalls only itself. The totals at the end
     * add everything up per config — what to hold against the database's connection limit
     * (configs pointing at one server add up there).
     */
    private function pool(): void
    {
        $records = PoolTelemetry::snapshot();

        if ($records === []) {
            self::printWarning('No pool telemetry found.');
            self::printInfo('A pool lives inside the running application, so the CLI reads what its'
                . ' workers and processes publish.');
            self::printInfo('Check that the application is running, that it queries the database, and that'
                . ' PPA_POOL_TELEMETRY is not 0 (current interval: ' . PoolTelemetry::interval() . 's).');
            return;
        }

        // An older winter-ppa (before 1.2) publishes web workers only and cannot filter
        // by kind: show the single group it knows.
        if (!defined(PoolTelemetry::class . '::KIND_WEB')) {
            $this->poolGroup('Connection pools', PoolTelemetry::aggregate(), 'workers');
            $this->poolSources($records);
            return;
        }

        $background = [PoolTelemetry::KIND_PROCESS, PoolTelemetry::KIND_DAEMON];
        $web        = PoolTelemetry::aggregate(PoolTelemetry::KIND_WEB);
        $bg         = PoolTelemetry::aggregate($background);
        $this->poolGroup('Connection pools — web workers', $web, 'workers');
        $this->poolGroup('Connection pools — processes', $bg, 'processes');

        self::printSplit('open connections per config');
        foreach (PoolTelemetry::aggregate() as $configClass => $stat) {
            self::printKeyValue($configClass, sprintf(
                '%d  (web %d + processes %d)',
                $stat['total'],
                $web[$configClass]['total'] ?? 0,
                $bg[$configClass]['total'] ?? 0,
            ), 40, 34, 36);
        }

        $this->poolSources($records);
    }

    /**
     * The per-source rows of {@see pool()}: one line per source and config.
     *
     * @param list<array<string, mixed>> $records
     */
    private function poolSources(array $records): void
    {
        self::printSplit('per source');
        $now = time();
        foreach ($records as $record) {
            $kind   = $record['kind'] ?? 'web';
            $source = $kind === 'web'
                ? 'worker#' . $record['worker']
                : $kind . ' ' . $record['worker'];
            foreach ($record['pools'] as $configClass => $stat) {
                self::printKeyValue(
                    $source,
                    sprintf(
                        '%-40s active=%d idle=%d total=%d max=%d  pid=%s  age=%ds',
                        $configClass,
                        $stat['active'],
                        $stat['idle'],
                        $stat['total'],
                        $stat['maximum'],
                        $record['pid'] ?? '-',
                        max(0, $now - (int) ($record['at'] ?? $now)),
                    ),
                    24,
                    34,
                    36,
                );
            }
        }
    }

    /**
     * One group of {@see pool()}: per config, the summed utilisation of its sources.
     * An empty group is skipped — an application without processes shows none.
     *
     * @param array<string, array{total: int, idle: int, active: int, maximum: int, workers: int,
     *     saturated: int}> $aggregate
     */
    private function poolGroup(string $title, array $aggregate, string $sources): void
    {
        if ($aggregate === []) {
            return;
        }

        self::printTitle($title);
        foreach ($aggregate as $configClass => $stat) {
            self::printLabel($configClass, 34);
            self::printKeyValue('active', (string) $stat['active'], 12, 34, 36);
            self::printKeyValue('idle', (string) $stat['idle'], 12, 34, 36);
            self::printKeyValue('total', (string) $stat['total'], 12, 34, 36);
            self::printKeyValue('maximum', (string) $stat['maximum'], 12, 34, 36);
            self::printKeyValue($sources, (string) $stat['workers'], 12, 34, 36);

            // Saturation is per source: a borrow queues on its own process's pool, so
            // one saturated source matters even when the group total shows slack.
            if ($stat['saturated'] > 0) {
                self::printKeyValue('saturated', "{$stat['saturated']} of {$stat['workers']} {$sources}", 12, 34, 33);
                self::printBadge($configClass, 'SATURATED', 34, 33);
            } else {
                self::printBadge($configClass, 'OK', 34, 32);
            }
        }
    }

    private function showSql(): void
    {
        foreach ($this->resolveTargets() as ['label' => $label, 'root' => $rootDir]) {
            $declaration = PPAMapping::scanningDeclaration($rootDir);

            $rawItems = $declaration->getItems();
            if (empty($rawItems)) {
                self::printWarning("[$label] No DB configs found — no entity has #[Table].");
                continue;
            }
            $items = $this->migratableItems($rawItems);
            if (empty($items)) {
                self::printWarning("[$label] No migratable configs — add #[Migratable] to a DbConfig to opt in.");
                continue;
            }

            foreach ($items as $item) {
                $data = $this->processItemData($item);
                self::printLabel("[$label] " . $item->config::class, 34);
                self::checkItem($item);

                if (in_array('e', $this->args['flags']) && count($data['sqlExtensions']) > 0) {
                    self::printLabel("Extensions (" . count($data['sqlExtensions']) . ")", 36);
                    foreach ($data['sqlExtensions'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                    self::printLabel("Extensions", 36);
                }

                if (in_array('s', $this->args['flags']) && count($data['sqlSchemes']) > 0) {
                    self::printLabel("Schemes (" . count($data['sqlSchemes']) . ")", 36);
                    foreach ($data['sqlSchemes'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                    self::printLabel("Schemes", 36);
                }

                if (in_array('t', $this->args['flags']) && count($data['sqlTables']) > 0) {
                    self::printLabel("Tables (" . count($data['sqlTables']) . ")", 36);
                    foreach ($data['sqlTables'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                    self::printLabel("Tables", 36);
                }

                if (in_array('i', $this->args['flags']) && count($data['sqlIndexes']) > 0) {
                    self::printLabel("Indexes (" . count($data['sqlIndexes']) . ")", 36);
                    foreach ($data['sqlIndexes'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                    self::printLabel("Indexes", 36);
                }

                if (in_array('c', $this->args['flags']) && count($data['sqlConstraints']) > 0) {
                    self::printLabel("Constraints (" . count($data['sqlConstraints']) . ")", 36);
                    foreach ($data['sqlConstraints'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                    self::printLabel("Constraints", 36);
                }

                self::printLabel("[$label] " . $item->config::class, 34);
            }
        }
    }

    private function migrate(): void
    {
        // Databases with a schema history (ppa/<Config>/) move by their versions; the
        // rest keep the create-if-missing pass below, as in 4.x.
        $versioned = $this->targetsPlugins() ? [] : $this->migrateVersioned();

        foreach ($this->resolveTargets() as ['label' => $label, 'root' => $rootDir]) {
            $declaration = PPAMapping::scanningDeclaration($rootDir);

            $rawItems = $declaration->getItems();
            if (empty($rawItems)) {
                self::printWarning("[$label] No DB configs found — no entity has #[Table].");
                continue;
            }
            $items = $this->migratableItems($rawItems);
            if (empty($items)) {
                self::printWarning("[$label] No migratable configs — add #[Migratable] to a DbConfig to opt in.");
                continue;
            }

            foreach ($items as $item) {
                if ($rootDir === null && in_array($item->config::class, $versioned, true)) {
                    continue;
                }
                $data = $this->processItemData($item);
                self::printLabel("[$label] " . $item->config::class, 34);
                if (!self::checkItem($item)) {
                    self::printBadge('migrate', 'REFUSED — nothing created', 34, 31);
                    continue;
                }
                if ($rootDir === null) {
                    self::printInfo('No schema history: `call db snapshot` records the first version, and from then on '
                        . '`db migrate` applies versions (changes to existing tables included).');
                }
                $db = $item->config->connection();

                // Extensions (pgsql only)
                if ($item->config->getDriver() === 'pgsql' && in_array('e', $this->args['flags'])) {
                    if (count($data['sqlExtensions']) > 0) {
                        self::printLabel("Extensions (" . count($data['sqlExtensions']) . ")", 36);
                        foreach ($data['sqlExtensions'] as $sql) {
                            try {
                                $db->exec($sql['exec']);
                                self::printBadge($sql['title'], 'OK', 34, 32);
                            } catch (\Throwable $e) {
                                self::printBadge($sql['title'], 'FAILED', 34, 31);
                                if (env('DEBUG', false)) {
                                    self::printInfo($e->getMessage());
                                }
                            }
                        }
                    }
                }

                // Schemes (pgsql only)
                if ($item->config->getDriver() === 'pgsql' && in_array('s', $this->args['flags'])) {
                    if (count($data['sqlSchemes']) > 0) {
                        self::printLabel("Schemes (" . count($data['sqlSchemes']) . ")", 36);
                        foreach ($data['sqlSchemes'] as $sql) {
                            try {
                                $db->exec($sql['exec']);
                                self::printBadge($sql['title'], 'OK', 34, 32);
                            } catch (\Throwable $e) {
                                if ($e->getCode() === '42P06') {
                                    self::printBadge($sql['title'], 'EXIST', 34, 33);
                                } else {
                                    self::printBadge($sql['title'], 'FAILED', 34, 31);
                                    if (env('DEBUG', false)) {
                                        self::printInfo($e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }

                // Tables
                if (in_array('t', $this->args['flags']) && count($data['sqlTables']) > 0) {
                    self::printLabel("Tables (" . count($data['sqlTables']) . ")", 36);
                    foreach ($data['sqlTables'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::printBadge($sql['title'], 'OK', 34, 32);
                        } catch (\Throwable $e) {
                            if (
                                ($item->config->getDriver() === 'pgsql' && $e->getCode() === '42P07')
                                || ($item->config->getDriver() === 'mysql' && $e->getCode() === '42S01')
                            ) {
                                self::printBadge($sql['title'], 'EXIST', 34, 33);
                            } else {
                                self::printBadge($sql['title'], 'FAILED', 34, 31);
                                if (env('DEBUG', false)) {
                                    self::printInfo($e->getMessage());
                                }
                            }
                        }
                    }
                }

                // Indexes
                if (in_array('i', $this->args['flags']) && count($data['sqlIndexes']) > 0) {
                    self::printLabel("Indexes (" . count($data['sqlIndexes']) . ")", 36);
                    foreach ($data['sqlIndexes'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::printBadge($sql['title'], 'OK', 34, 32);
                        } catch (\Throwable $e) {
                            // MySQL/MariaDB report every CREATE INDEX error as SQLSTATE 42000 — a
                            // duplicate name, an unknown column, a syntax error alike. Only the
                            // driver code 1061 ("Duplicate key name") means the index is there;
                            // reading the whole class as EXIST hid indexes the server rejected.
                            if (
                                ($item->config->getDriver() === 'pgsql' && $e->getCode() === '42P07')
                                || ($item->config->getDriver() === 'mysql' && self::driverErrorCode($e) === 1061)
                            ) {
                                self::printBadge($sql['title'], 'EXIST', 34, 33);
                            } else {
                                self::printBadge($sql['title'], 'FAILED', 34, 31);
                                if (env('DEBUG', false)) {
                                    self::printInfo($e->getMessage());
                                }
                            }
                        }
                    }
                }

                // Constraints
                if (in_array('c', $this->args['flags']) && count($data['sqlConstraints']) > 0) {
                    self::printLabel("Constraints (" . count($data['sqlConstraints']) . ")", 36);
                    foreach ($data['sqlConstraints'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::printBadge($sql['title'], 'OK', 34, 32);
                        } catch (\Throwable $e) {
                            if ($e->getCode() === '42710') {
                                self::printBadge($sql['title'], 'EXIST', 34, 33);
                            } else {
                                self::printBadge($sql['title'], 'FAILED', 34, 31);
                                if (env('DEBUG', false)) {
                                    self::printInfo($e->getMessage());
                                }
                            }
                        }
                    }
                }

                self::printLabel("[$label] " . $item->config::class, 34);
            }
        }
    }

    /**
     * Filters items down to those opted into migration via #[Migratable]
     * and sorts them by declared priority (High → Normal → Low).
     *
     * @param  DeclarationItem[] $items
     * @return DeclarationItem[]
     */
    private function migratableItems(array $items): array
    {
        $filtered = array_values(array_filter($items, static fn (DeclarationItem $i): bool => $i->isMigratable()));
        usort(
            $filtered,
            static fn (DeclarationItem $a, DeclarationItem $b): int
                => $a->getPriority()->value <=> $b->getPriority()->value,
        );
        return $filtered;
    }

    /**
     * @return array{
     *     sqlExtensions: array,
     *     sqlSchemes: array,
     *     sqlTables: array,
     *     sqlIndexes: array,
     *     sqlConstraints: array
     * }
     */
    private function processItemData(DeclarationItem $item): array
    {
        $sqlExtensions = [];
        $sqlSchemes = [];
        $sqlTables = [];
        $sqlIndexes = [];
        $sqlConstraints = [];

        $item->config->setUp();
        $driver = $item->config->getDriver();

        if ($driver === 'pgsql') {
            foreach ($item->getExtensions() as $extension) {
                $sqlExtensions[] = [
                    'title' => $extension->name,
                    'exec'  => $extension->toSql($driver),
                ];
            }
        }

        foreach ($item->getTables() as $structure) {
            if ($structure instanceof Table) {
                $schemaSql = $structure->createSchemaIfNotExists($item->config->getDriver());
                if ($schemaSql !== null) {
                    $title = (string) $structure->schema;
                    if (!isset($sqlSchemes[$title])) {
                        $sqlSchemes[$title] = ['title' => $title, 'exec' => $schemaSql];
                    }
                }
                $sql = $structure->toSql($item->config->getDriver());
                $exp = explode(PHP_EOL . ');' . PHP_EOL, $sql);

                $sqlTables[] = [
                    'title' => $structure->getFullName(),
                    'exec' => (count($exp) == 1 ? $exp[0] : $exp[0] . PHP_EOL . ');')
                ];
                if (count($exp) > 1) {
                    foreach (explode(PHP_EOL, $exp[1]) as $line) {
                        if (str_starts_with($line, 'ALTER TABLE')) {
                            preg_match('/ADD\s+CONSTRAINT\s+([a-zA-Z0-9_]+)/i', $line, $match);
                            $sqlConstraints[] = [
                                'title' => "constraint '" . ($match[1] ?? 'unknown') . "'",
                                'exec'  => $line,
                            ];
                        } else {
                            preg_match('/\bINDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z0-9_]+)/i', $line, $match);
                            $sqlIndexes[] = [
                                'title' => "index '" . ($match[1] ?? 'unknown') . "'",
                                'exec'  => $line,
                            ];
                        }
                    }
                }
            }
        }

        return [
            'sqlExtensions'   => $sqlExtensions,
            'sqlSchemes'      => $sqlSchemes,
            'sqlTables'       => $sqlTables,
            'sqlIndexes'      => $sqlIndexes,
            'sqlConstraints'  => $sqlConstraints,
        ];
    }

    // --- Schema versions (ppa/<Config>/) ---

    private function targetsPlugins(): bool
    {
        return isset($this->args['options']['plugin']) || isset($this->args['options']['plugins']);
    }

    /**
     * Applies the pending versions of every database with a history.
     *
     * @return list<string> The config classes it handled — the legacy pass skips them.
     * A database that fails ends the command with 1.
     */
    private function migrateVersioned(): array
    {
        $handled = [];
        $failed = [];
        $dry = isset($this->args['options']['dry']);
        foreach ($this->schemaDatabases() as $db) {
            if (!$db['dir']->exists()) {
                continue;
            }
            $handled[] = $db['config']::class;
            self::printLabel(self::header($db), 34);
            try {
                $migrator = PpaSchema::migrator($db, self::consoleLogger());
                if ($dry) {
                    $this->printPlan($migrator->status(), $db['driver']);
                } else {
                    $done = $migrator->migrate();
                    if ($done === []) {
                        self::printBadge('migrate', 'UP TO DATE', 34, 32);
                    }
                    foreach ($done as $d) {
                        $state = strtoupper($d['action']) . ($d['ms'] ? " {$d['ms']} ms" : '');
                        self::printBadge($d['version'], $state, 34, 32);
                    }
                }
            } catch (\Throwable $e) {
                self::printBadge('migrate', 'FAILED', 34, 31);
                self::printWarning($e->getMessage());
                $failed[] = self::shortName($db['config']::class);
            }
            $db['config']->disconnect();
        }
        if ($failed !== []) {
            self::fail('db migrate failed: ' . implode(', ', $failed) . '.');
        }
        return $handled;
    }

    /** `--dry`: what `migrate` would run, statement by statement. */
    private function printPlan(array $status, string $driver): void
    {
        foreach ($status['problems'] as $p) {
            self::printWarning($p);
        }
        if ($status['pending'] === []) {
            self::printBadge('migrate', 'UP TO DATE', 34, 32);
            return;
        }
        if ($status['state'] === 'fresh') {
            self::printInfo('A fresh database: created from the model of ' . end($status['versions'])->version
                . ' at once, ' . count($status['versions']) . ' version(s) recorded, no version code run.');
            $fresh = SchemaSql::create(
                SchemaModel::fold($status['versions']),
                $driver,
                SchemaModel::objects($status['versions']),
            );
            foreach ($fresh as $sql) {
                self::printSplit($sql . ';', 36);
            }
            return;
        }
        if ($status['state'] === 'unversioned') {
            self::printInfo(
                'Tables without a history: the latest version they match is recorded as baseline, the rest applied.'
            );
        }
        foreach ($status['pending'] as $v) {
            self::printLabel($v->label() . ($v->hasCode() ? '   + code: before() / after()' : ''), 36);
            foreach (SchemaSql::plan($v->changes, $driver) as ['change' => $c, 'sql' => $sql]) {
                if ($sql === []) {
                    self::printInfo(SchemaSnapshot::describe($c));
                }
                foreach ($sql as $statement) {
                    self::printSplit($statement . ';', 36);
                }
            }
        }
    }

    /** `call db snapshot [custom <name>]` — records a version from the entities. */
    private function snapshot(): void
    {
        $custom = ($this->args['arguments'][2] ?? '') === 'custom';
        $name = $custom ? ($this->args['arguments'][3] ?? null) : ($this->args['options']['name'] ?? null);
        if ($custom && $name === null) {
            self::printWarning('Usage: call db snapshot custom <name>');
            return;
        }
        $drop = self::csv($this->args['options']['drop'] ?? null);
        $keep = self::csv($this->args['options']['keep'] ?? null);
        $only = $this->args['options']['db'] ?? null;
        $dry = isset($this->args['options']['dry']);
        $refused = false;
        $dbs = $this->schemaDatabases();
        if ($dbs === []) {
            self::printWarning('No #[Migratable] database has entities — nothing to snapshot.');
            return;
        }
        foreach ($dbs as $db) {
            if ($only !== null && strcasecmp($only, $db['name']) !== 0) {
                continue;
            }
            $folder = PpaSchema::DIR . "/{$db['name']}/";
            self::printLabel(self::header($db), 34);
            $diff = SchemaSnapshot::diff(
                $db['dir'],
                $db['tables'],
                $db['driver'],
                $drop,
                $keep,
                $custom,
                $db['extensions'],
            );
            foreach ($diff->notices as $n) {
                self::printInfo($n);
            }
            foreach ($diff->changes as $c) {
                self::print('  ' . SchemaSnapshot::describe($c), ($c['by'] ?? null) === 'code' ? 33 : 36);
            }
            if ($diff->errors !== []) {
                foreach ($diff->errors as $e) {
                    self::printWarning($e);
                }
                self::printBadge('snapshot', 'NOT WRITTEN', 34, 31);
                $refused = true;
                continue;
            }
            if ($diff->isEmpty() && !$custom) {
                self::printBadge('snapshot', 'NOTHING TO RECORD', 34, 33);
                continue;
            }
            if ($diff->isEmpty()) {
                self::printInfo('No schema change: a version of code only — its before() / after() change the data.');
            }
            if ($diff->needsCode() && !$custom) {
                self::printWarning('Some changes are left to code: record them with `call db snapshot custom <name>`.');
                $refused = true;
                continue;
            }
            if ($dry) {
                self::printBadge('snapshot', 'DRY — nothing written', 34, 33);
                continue;
            }
            // The config's class name as the namespace of the version's code: a V003_FillStock
            // of each database, or of the application and a package, in one process.
            $written = SchemaSnapshot::write($db['dir'], $diff, $db['driver'], $name, $custom, $db['config']::class);
            foreach ($written['files'] as $file) {
                self::printBadge($folder . substr($file, strlen($db['dir']->path) + 1), 'WRITTEN', 34, 32);
            }
            if ($custom) {
                self::printInfo('Fill in before() / after() of ' . $folder . SchemaVersionFile::CODE_DIR . '/'
                    . basename(end($written['files']))
                    . ' — after() makes what is marked [by code].');
            }
            if ($diff->stale !== []) {
                self::printInfo('`call db snapshot clean` removes the #[RenamedFrom] already recorded.');
            }
        }
        if ($refused) {
            self::fail('db snapshot: nothing was written for a database with errors (see above).');
        }
    }

    /**
     * Ends the command with 1 after a refusal already explained above it — a warning, not
     * an exception: there is no bug to trace, and a trace under DEBUG buries the reason.
     */
    private static function fail(string $message): never
    {
        self::printWarning($message);
        self::printTitle("Db", 34);
        exit(1);
    }

    /** `call db snapshot clean [--dry]` — removes the #[RenamedFrom] the history already has. */
    private function snapshotClean(): void
    {
        $dry = isset($this->args['options']['dry']);
        $byFile = [];
        $needed = [];
        $stale = [];
        foreach ($this->schemaDatabases() as $db) {
            $diff = SchemaSnapshot::diff($db['dir'], $db['tables'], $db['driver'], extensions: $db['extensions']);
            foreach ($diff->needed as $n) {
                $needed[$n['entity'] . '::' . $n['property']] = $db['name'];
            }
            array_push($stale, ...$diff->stale);
        }
        $told = [];
        foreach ($stale as $s) {
            if ($s['entity'] === null) {
                continue;
            }
            $key = $s['entity'] . '::' . $s['property'];
            // An entity of several databases keeps it while one of them has not recorded it.
            if (isset($needed[$key])) {
                if (!isset($told[$key])) {
                    self::printInfo("{$s['entity']}::{$s['property']} keeps #[RenamedFrom('{$s['from']}')]: "
                        . "the history of '{$needed[$key]}' has not recorded it yet.");
                    $told[$key] = true;
                }
                continue;
            }
            $file = (string) (new \ReflectionClass($s['entity']))->getFileName();
            // An entity shared by several databases is cleaned once.
            $byFile[$file][$s['property']] = $s;
        }
        if ($byFile === []) {
            self::printBadge('snapshot clean', 'NOTHING TO CLEAN', 34, 33);
            return;
        }
        foreach ($byFile as $file => $stale) {
            $rel = str_starts_with($file, Kernel::$pathRoot)
                ? ltrim(substr($file, strlen(Kernel::$pathRoot)), '/')
                : $file;
            foreach ($stale as $s) {
                $on = str_starts_with($s['property'], '@')
                    ? 'the class ' . substr($s['property'], 1)
                    : "\${$s['property']}";
                self::printInfo("{$rel}: #[RenamedFrom('{$s['from']}')] on {$on} — recorded in {$s['version']}");
            }
            $out = RenamedFromCleaner::clean((string) file_get_contents($file), array_keys($stale));
            if ($dry) {
                self::printBadge($rel, "DRY — {$out['removed']} would go", 34, 33);
                continue;
            }
            file_put_contents($file, $out['source']);
            self::printBadge($rel, "CLEANED {$out['removed']}", 34, 32);
        }
    }

    /** `call db status` — versions applied and pending, and whether the entities moved on. */
    private function status(): void
    {
        $dbs = $this->schemaDatabases();
        if ($dbs === []) {
            self::printWarning('No #[Migratable] database has entities.');
            return;
        }
        $only = $this->args['options']['db'] ?? null;
        foreach ($dbs as $db) {
            if ($only !== null && strcasecmp($only, $db['name']) !== 0) {
                continue;
            }
            $folder = PpaSchema::DIR . "/{$db['name']}/";
            self::printLabel(self::header($db), 34);
            if (!$db['dir']->exists()) {
                self::printWarning("No schema history yet — `call db snapshot` records the first version in {$folder}");
                continue;
            }
            try {
                $st = PpaSchema::migrator($db, new NullLogger())->status();
                foreach ($st['versions'] as $v) {
                    $row = $st['rows'][$v->version] ?? null;
                    $state = $row['state'] ?? 'pending';
                    $mark = match ($state) {
                        'pending' => '○',
                        'failed', 'running' => '✖',
                        default => '✔',
                    };
                    $when = $row !== null ? substr((string) $row['applied_at'], 0, 19) : '';
                    $color = $state === 'pending' ? 33 : ($mark === '✖' ? 31 : 32);
                    $text = trim("{$state} {$when}") . ($v->hasCode() ? '   + code' : '');
                    self::printKeyValue("{$mark} {$v->label()}", $text, 52, 34, $color);
                }
                foreach ($st['problems'] as $p) {
                    self::printWarning($p);
                }
                self::printInfo(match ($st['state']) {
                    'fresh' => 'A fresh database: `db migrate` creates it at the last version at once.',
                    'unversioned' => 'Tables without a history: `db migrate` records the version they match '
                        . 'as baseline.',
                    'behind' => count($st['pending']) . ' version(s) pending — `call db migrate`.',
                    'current' => 'Up to date.',
                    default => 'No versions.',
                });
            } catch (\Throwable $e) {
                self::printWarning('Cannot read the history: ' . $e->getMessage());
            }
            $diff = SchemaSnapshot::diff($db['dir'], $db['tables'], $db['driver'], extensions: $db['extensions']);
            foreach ($diff->notices as $n) {
                self::printInfo($n);
            }
            if (!$diff->isEmpty() || $diff->errors !== []) {
                $todo = $diff->errors === [] ? '' : ', ' . count($diff->errors) . ' to decide';
                self::printWarning('The entities differ from the last version (' . count($diff->changes)
                    . " change(s){$todo}) — `call db snapshot` records them.");
            }
            $db['config']->disconnect();
        }
        foreach (PpaSchema::strays($dbs) as $stray) {
            self::printWarning(PpaSchema::DIR . "/{$stray}/ has versions, but no #[Migratable] config is called "
                . "{$stray}: renamed? Rename the folder after the config.");
        }
    }

    /**
     * What the server would not build as the entities declare it ({@see SchemaCheck}):
     * notices and errors said; false when there is an error.
     */
    private static function checkItem(DeclarationItem $item): bool
    {
        if (!class_exists(SchemaCheck::class)) {
            return true;            // a winter-ppa from before the check
        }
        $check = SchemaCheck::tables(
            $item->getTables(),
            $item->config->getDriver(),
            array_map(static fn($e) => $e->name, $item->getExtensions()),
        );
        foreach ($check['notices'] as $n) {
            self::printInfo($n);
        }
        foreach ($check['errors'] as $e) {
            self::printWarning($e);
        }
        return $check['errors'] === [];
    }

    /** @return list<array> */
    private function schemaDatabases(): array
    {
        return PpaSchema::databases();
    }

    /** @return list<string> */
    private static function csv(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }

    /** `MainDb (pgsql) — ppa/MainDb/` */
    private static function header(array $db): string
    {
        return self::shortName($db['config']::class) . " ({$db['driver']}) — " . PpaSchema::DIR . "/{$db['name']}/";
    }

    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos('\\' . $class, '\\'));
    }

    /** What a migration says, as console lines. */
    private static function consoleLogger(): LoggerInterface
    {
        return new ConsoleLogger();
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Db Help", $cl);

        // Said before the menu rather than after it. Help is printed by isHelp(), which
        // runs before handle() and exits — so this page is reachable without the package
        // and would otherwise offer four subcommands, five flags and seven examples, none
        // of which can run. The requirement is a permanent property of the command, so it
        // is stated whether or not the package happens to be present.
        self::printLabel("Requires", $cl);
        self::print('the database layer — ' . Dep::Ppa->package(), $cl);
        if (!DepSupport::has(Dep::Ppa)) {
            self::printWarning('Not installed: every command below will refuse to run.');
            self::printInfo('composer require ' . Dep::Ppa->package());
        }
        self::printLabel("Requires", $cl);

        self::printLabel("Usage", $cl);
        self::print("call db [command] -[flags] --[options]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('ping', 'check DB connection and latency', $cl, 36);
        self::printBadge('migrate', 'run migrations against connected databases', $cl, 36);
        self::printBadge('sql', 'preview generated SQL without executing', $cl, 36);
        self::printBadge('pool', 'show connection-pool utilisation of the running application', $cl, 36);
        self::printBadge('snapshot', 'record the entities as a new schema version (ppa/<Config>/)', $cl, 36);
        self::printBadge('snapshot custom <name>', 'the same, with a class for what the kernel cannot do', $cl, 36);
        self::printBadge('snapshot clean', 'remove the #[RenamedFrom] the history already recorded', $cl, 36);
        self::printBadge('status', 'schema versions applied and pending, entities vs the last version', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-e", "extensions only (pgsql)", 10, $cl, 36);
        self::printKeyValue("-s", "schemes only", 10, $cl, 36);
        self::printKeyValue("-t", "tables only", 10, $cl, 36);
        self::printKeyValue("-i", "indexes only", 10, $cl, 36);
        self::printKeyValue("-c", "constraints only", 10, $cl, 36);
        self::printInfo("(no flags = all: -e -s -t -i -c)");
        self::printLabel("Flags", $cl);

        self::printLabel("Options", $cl);
        self::printKeyValue("--plugin=<name>", "target one imported package (composer name)", 20, $cl, 36);
        self::printKeyValue("--plugins", "target every imported package", 20, $cl, 36);
        self::printInfo("(no option = app)");
        self::printKeyValue("--name=<name>", "snapshot: the version's name (default: from its changes)", 20, $cl, 36);
        self::printKeyValue("--drop=<t.c,…>", "snapshot: tables / columns gone that may be dropped", 20, $cl, 36);
        self::printKeyValue("", "  extension:<name> — an extension no longer declared", 20, $cl, 36);
        self::printKeyValue("--keep=<t.c,…>", "snapshot: columns gone, kept in the database", 20, $cl, 36);
        self::printKeyValue("--db=<Config>", "snapshot / status: one database (its config class)", 20, $cl, 36);
        self::printKeyValue("--dry", "snapshot / clean / migrate: show, change nothing", 20, $cl, 36);
        self::printInfo("With ppa/<Config>/ a database migrates by its versions; without, by create-if-missing.");
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call db ping");
        self::printInfo("call db ping --plugins");
        self::printInfo("call db migrate");
        self::printInfo("call db migrate -t -i");
        self::printInfo("call db migrate --plugin=acme/billing");
        self::printInfo("call db migrate --plugins");
        self::printInfo("call db sql --plugin=acme/billing -s");
        self::printInfo("call db snapshot");
        self::printInfo("call db snapshot --drop=orders.legacy_code");
        self::printInfo("call db snapshot custom change_order_id");
        self::printInfo("call db snapshot clean --dry");
        self::printInfo("call db status");
        self::printInfo("call db migrate --dry");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-db");

        self::printTitle("Db Help", $cl);
    }

    /**
     * The driver's own error number behind a database failure (MySQL's 1061, …), or null when
     * there is none — read from the first PDOException in the chain, since a wrapper may
     * carry it as its previous.
     */
    private static function driverErrorCode(\Throwable $e): ?int
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
                return (int) $cause->errorInfo[1];
            }
        }
        return null;
    }
}
