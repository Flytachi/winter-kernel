<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Ppa\Declaration;
use Flytachi\Winter\K2\Ppa\PPAMapping;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\Kernel\Factory\Plugin;

class Db extends Cmd
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
        if (empty($this->args['flags'])) {
            $this->args['flags'] = ['s', 't', 'i', 'c'];
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
        foreach ($this->resolveTargets() as $label => $rootDir) {
            $declaration = PPAMapping::scanningDeclaration($rootDir);

            $seen = [];
            foreach ($declaration->getItems() as $item) {
                $configClass = $item->config::class;
                if (isset($seen[$configClass])) {
                    continue;
                }
                $seen[$configClass] = true;

                $detail = $item->config->pingDetail();

                self::printLabel("[$label] $configClass", 34);
                self::printKeyValue("driver", $item->config->getDriver(), 12, 34, 36);
                self::printKeyValue("dsn", $item->config->getDns(), 12, 34, 36);
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

            if (empty($seen)) {
                self::printWarning("[$label] No repositories found — nothing to ping.");
            }
        }
    }

    private function showSql(): void
    {
        foreach ($this->resolveTargets() as $label => $rootDir) {
            $declaration = PPAMapping::scanningDeclaration($rootDir);
            $data = $this->processDeclarationData($declaration);

            foreach ($declaration->getItems() as $item) {
                self::printLabel("[$label] " . $item->config::class, 34);

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
            $data = $this->processDeclarationData($declaration);

            foreach ($declaration->getItems() as $item) {
                self::printLabel("[$label] " . $item->config::class, 34);
                $db = $item->config->connection();

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
     * @return array{sqlSchemes: array, sqlTables: array, sqlIndexes: array, sqlConstraints: array}
     */
    private function processDeclarationData(Declaration $declaration): array
    {
        $sqlSchemes = [];
        $sqlTables = [];
        $sqlIndexes = [];
        $sqlConstraints = [];

        foreach ($declaration->getItems() as $item) {
            $item->config->setUp();

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
        }

        return [
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
        self::printLabel("Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-s", "schemes only", 10, $cl, 36);
        self::printKeyValue("-t", "tables only", 10, $cl, 36);
        self::printKeyValue("-i", "indexes only", 10, $cl, 36);
        self::printKeyValue("-c", "constraints only", 10, $cl, 36);
        self::printInfo("(no flags = all: -s -t -i -c)");
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
        self::printInfo("Docs: https://winterframe.net/docs/2.0.0/cmd-db");

        self::printTitle("Db Help", $cl);
    }
}
