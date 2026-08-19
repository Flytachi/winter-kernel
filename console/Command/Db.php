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
use Flytachi\Winter\Kernel\Plugin;

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
            self::printError($e->getMessage());
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
            default:
                self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                self::printInfo("Run 'call db --help' to see available commands.");
                break;
        }
    }

    // --- Plugin resolution ---

    /**
     * @return array<string, string|null>  prefix => rootDir (null = app)
     */
    private function resolveTargets(): array
    {
        $options = $this->args['options'];

        if (isset($options['plugin'])) {
            $prefix = $options['plugin'];
            $plugins = Plugin::getPlugins();
            if (!isset($plugins[$prefix])) {
                self::printWarning("Plugin '$prefix' is not registered.");
                return [];
            }
            return [$prefix => $plugins[$prefix]];
        }

        if (isset($options['plugins'])) {
            $plugins = Plugin::getPlugins();
            if (empty($plugins)) {
                self::printWarning("No plugins are registered.");
                return [];
            }
            return $plugins;
        }

        return ['Project' => null];
    }

    // --- Commands ---

    private function ping(): void
    {
        $targets = ['Project' => null] + Plugin::getPlugins();
        foreach ($targets as $label => $rootDir) {
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
     * Shows connection-pool utilisation of the running server.
     *
     * A pool lives in one worker's memory and the CLI is a separate process, so this
     * reads what each worker publishes to the shared store ({@see PoolTelemetry}) —
     * the same indirection `call process status` uses. Numbers are therefore as fresh
     * as the last publish (see the `age` column), and a stopped worker's record simply
     * expires.
     */
    private function pool(): void
    {
        $records = PoolTelemetry::snapshot();

        if ($records === []) {
            self::printWarning('No pool telemetry found.');
            self::printInfo('A pool lives inside the running server, so the CLI reads what workers publish.');
            self::printInfo('Check that the server is running, that it queries the database, and that'
                . ' PPA_POOL_TELEMETRY is not 0 (current interval: ' . PoolTelemetry::interval() . 's).');
            return;
        }

        self::printTitle('Connection pools');
        foreach (PoolTelemetry::aggregate() as $configClass => $stat) {
            self::printLabel($configClass, 34);
            self::printKeyValue('active', (string) $stat['active'], 12, 34, 36);
            self::printKeyValue('idle', (string) $stat['idle'], 12, 34, 36);
            self::printKeyValue('total', (string) $stat['total'], 12, 34, 36);
            self::printKeyValue('maximum', (string) $stat['maximum'], 12, 34, 36);
            self::printKeyValue('workers', (string) $stat['workers'], 12, 34, 36);

            // Saturation is per worker: a borrow queues on its own worker's pool, so
            // one saturated worker matters even when the fleet total shows slack.
            if ($stat['saturated'] > 0) {
                self::printKeyValue('saturated', "{$stat['saturated']} of {$stat['workers']} workers", 12, 34, 33);
                self::printBadge($configClass, 'SATURATED', 34, 33);
            } else {
                self::printBadge($configClass, 'OK', 34, 32);
            }
        }

        self::printSplit('per worker');
        $now = time();
        foreach ($records as $record) {
            foreach ($record['pools'] as $configClass => $stat) {
                self::printKeyValue(
                    'worker#' . $record['worker'],
                    sprintf(
                        '%-40s active=%d idle=%d total=%d max=%d  age=%ds',
                        $configClass,
                        $stat['active'],
                        $stat['idle'],
                        $stat['total'],
                        $stat['maximum'],
                        max(0, $now - (int) ($record['at'] ?? $now)),
                    ),
                    12,
                    34,
                    36,
                );
            }
        }
    }

    private function showSql(): void
    {
        foreach ($this->resolveTargets() as $label => $rootDir) {
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
        foreach ($this->resolveTargets() as $label => $rootDir) {
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
                            if (
                                ($item->config->getDriver() === 'pgsql' && $e->getCode() === '42P07')
                                || ($item->config->getDriver() === 'mysql' && $e->getCode() === '42000')
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
                    $title = str_replace(';', '', str_replace('CREATE SCHEMA ', '', $schemaSql));
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

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Db Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call db [command] -[flags] --[options]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('ping', 'check DB connection and latency', $cl, 36);
        self::printBadge('migrate', 'run migrations against connected databases', $cl, 36);
        self::printBadge('sql', 'preview generated SQL without executing', $cl, 36);
        self::printBadge('pool', 'show connection-pool utilisation of the running server', $cl, 36);
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
        self::printKeyValue("--plugin=<name>", "target a single registered plugin", 20, $cl, 36);
        self::printKeyValue("--plugins", "target all registered plugins", 20, $cl, 36);
        self::printInfo("(no option = app)");
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call db ping");
        self::printInfo("call db ping --plugins");
        self::printInfo("call db migrate");
        self::printInfo("call db migrate -t -i");
        self::printInfo("call db migrate --plugin=bill");
        self::printInfo("call db migrate --plugins");
        self::printInfo("call db sql --plugin=bill -s");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/cmd-db");

        self::printTitle("Db Help", $cl);
    }
}
