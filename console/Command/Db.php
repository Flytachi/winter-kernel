<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Edo\Declaration;
use Flytachi\Winter\Edo\Mapping\Structure\Table;
use Flytachi\Winter\Kernel\Factory\EdoMapping;

class Db extends Cmd
{
    public static string $title = "command database control";

    public function handle(): void
    {
        self::printTitle("Db", 32);

        if (
            count($this->args['arguments']) > 1
        ) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Db", 32);
    }

    private function resolution(): void
    {
        if (array_key_exists(1, $this->args['arguments'])) {
            if (empty($this->args['flags'])) {
                $this->args['flags'] = ['s', 't', 'i', 'c'];
            }
            switch ($this->args['arguments'][1]) {
                case 'migrate':
                    $this->migrate();
                    break;
                case 'sql':
                    $this->showSql();
                    break;
                default:
                    self::printMessage("Argument '{$this->args['arguments'][1]}' not found");
                    break;
            }
        }
    }

    private function showSql(): void
    {
        $declaration = EdoMapping::scanningDeclaration();
        $data = $this->processDeclarationData($declaration);

        foreach ($declaration->getItems() as $item) {
            self::printLabel($item->config::class, 32);

            // Schemes
            if (in_array('s', $this->args['flags'])) {
                if (count($data['sqlSchemes']) > 0) {
                    self::printMessage("* Schemes (" . count($data['sqlSchemes']) . ")", 32);
                    foreach ($data['sqlSchemes'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                }
            }

            // Tables
            if (in_array('t', $this->args['flags'])) {
                if (count($data['sqlTables']) > 0) {
                    self::printMessage("* Tables (" . count($data['sqlTables']) . ")", 32);
                    foreach ($data['sqlTables'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                }
            }

            // Indexes
            if (in_array('i', $this->args['flags'])) {
                if (count($data['sqlIndexes']) > 0) {
                    self::printMessage("* Indexes (" . count($data['sqlIndexes']) . ")", 32);
                    foreach ($data['sqlIndexes'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                }
            }

            // Constraints
            if (in_array('c', $this->args['flags'])) {
                if (count($data['sqlConstraints']) > 0) {
                    self::printMessage("* Constraints (" . count($data['sqlConstraints']) . ")", 32);
                    foreach ($data['sqlConstraints'] as $sql) {
                        self::printSplit($sql['exec']);
                    }
                }
            }

            self::printLabel($item->config::class, 32);
        }
    }

    private function migrate(): void
    {
        $declaration = EdoMapping::scanningDeclaration();
        $data = $this->processDeclarationData($declaration);

        foreach ($declaration->getItems() as $item) {
            self::printLabel($item->config::class, 32);
            $db = $item->config->connection();

            // Schemes
            if (in_array('s', $this->args['flags'])) {
                if (count($data['sqlSchemes']) > 0) {
                    self::printMessage("* Schemes (" . count($data['sqlSchemes']) . ")", 32);
                    foreach ($data['sqlSchemes'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::print("- [ok] scheme '{$sql['title']}'", 32);
                        } catch (\Throwable $e) {
                            if ($e->getCode() === '42P06') {
                                self::print("- [exist] scheme '{$sql['title']}'", 33);
                            } else {
                                self::print("- [failed] scheme '{$sql['title']}'", 31);
                                if (env('DEBUG', false)) {
                                    self::print("\t" . $e->getMessage(), 31);
                                }
                            }
                        }
                    }
                }
            }

            // Tables
            if (in_array('t', $this->args['flags'])) {
                if (count($data['sqlTables']) > 0) {
                    self::printMessage("* Tables (" . count($data['sqlTables']) . ")", 32);
                    foreach ($data['sqlTables'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::print("- [ok] table '{$sql['title']}'", 32);
                        } catch (\Throwable $e) {
                            if ($e->getCode() === '42P07') {
                                self::print("- [exist] table '{$sql['title']}'", 33);
                            } else {
                                self::print("- [failed] table '{$sql['title']}'", 31);
                                if (env('DEBUG', false)) {
                                    self::print("\t" . $e->getMessage(), 31);
                                }
                            }
                        }
                    }
                }
            }

            // Indexes
            if (in_array('i', $this->args['flags'])) {
                if (count($data['sqlIndexes']) > 0) {
                    self::printMessage("* Indexes (" . count($data['sqlIndexes']) . ")", 32);
                    foreach ($data['sqlIndexes'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::print("- [ok] " . $sql['title'], 32);
                        } catch (\Throwable $e) {
                            if ($e->getCode() === '42P07') {
                                self::print("- [exist] " . $sql['title'], 33);
                            } else {
                                self::print("- [failed] " . $sql['title'], 31);
                                if (env('DEBUG', false)) {
                                    self::print("\t" . $e->getMessage(), 31);
                                }
                            }
                        }
                    }
                }
            }

            // Constraints
            if (in_array('c', $this->args['flags'])) {
                if (count($data['sqlConstraints']) > 0) {
                    self::printMessage("* Constraints (" . count($data['sqlConstraints']) . ")", 32);
                    foreach ($data['sqlConstraints'] as $sql) {
                        try {
                            $db->exec($sql['exec']);
                            self::print("- [ok] " . $sql['title'], 32);
                        } catch (\Throwable $e) {
                            if ($e->getCode() === '42710') {
                                self::print("- [exist] " . $sql['title'], 33);
                            } else {
                                self::print("- [failed] " . $sql['title'], 31);
                                if (env('DEBUG', false)) {
                                    self::print("\t" . $e->getMessage(), 31);
                                }
                            }
                        }
                    }
                }
            }

            self::printLabel($item->config::class, 32);
        }
    }

    /**
     * Processes the database declaration and prepares SQL statements.
     *
     * @param Declaration $declaration
     * @return array{sqlSchemes: array, sqlTables: array, sqlIndexes: array, sqlConstraints: array}
     * An associative array containing 'sqlSchemes', 'sqlTables', 'sqlIndexes', 'sqlConstraints'.
     */
    private function processDeclarationData(Declaration $declaration): array
    {
        $sqlSchemes = [];
        $sqlTables = [];
        $sqlIndexes = [];
        $sqlConstraints = [];

        foreach ($declaration->getItems() as $item) {
            $item->config->sepUp();

            foreach ($item->getTables() as $structure) {
                if ($structure instanceof Table) {
                    $schemaSql = $structure->createSchemaIfNotExists($item->config->getDriver());
                    if ($schemaSql !== null) {
                        $title = str_replace(
                            ';',
                            '',
                            str_replace('CREATE SCHEMA ', '', $schemaSql)
                        );
                        if (!isset($sqlSchemes[$title])) {
                            $sqlSchemes[$title] = [
                                'title' => $title,
                                'exec' => $schemaSql,
                            ];
                        }
                    }
                    $sql = $structure->toSql($item->config->getDriver());
                    $exp = explode(PHP_EOL . ');' . PHP_EOL, $sql);

                    $sqlTables[] = [
                        'title' => $structure->getFullName(),
                        'exec' => (count($exp) == 1 ? $exp[0] : $exp[0] . PHP_EOL . ');')
                    ];
                    if (count($exp) > 1) {
                        $subExp = explode(PHP_EOL, $exp[1]);
                        for ($i = 0; $i < count($subExp); $i++) {
                            if (str_starts_with($subExp[$i], 'ALTER TABLE')) {
                                preg_match('/ADD\s+CONSTRAINT\s+([a-zA-Z0-9_]+)/i', $subExp[$i], $match);
                                $title = $match[1] ?? 'unknown';
                                $sqlConstraints[] = [
                                    'title' => "constraint '{$title}'",
                                    'exec' =>  $subExp[$i]
                                ];
                            } else {
                                preg_match(
                                    '/\bINDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z0-9_]+)/i',
                                    $subExp[$i],
                                    $match
                                );
                                $title = $match[1] ?? 'unknown';
                                $sqlIndexes[] = [
                                    'title' => "index '{$title}'",
                                    'exec' =>  $subExp[$i]
                                ];
                            }
                        }
                    }
                }
            }
        }

        return [
            'sqlSchemes' => $sqlSchemes,
            'sqlTables' => $sqlTables,
            'sqlIndexes' => $sqlIndexes,
            'sqlConstraints' => $sqlConstraints,
        ];
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Db Help", $cl);

        self::printLabel("extra db [args...] -[flags...] --[options...]", $cl);
        self::printMessage("args - command", $cl);
        self::print("migrate - migration mapping sql in databases", $cl);
        self::print("sql - show mapping sql", $cl);

        // migrate
        self::printLabel("migrate", $cl);
        self::printMessage("flags - selection additional to be action", $cl);
        self::print("s - migrate only schemes", $cl);
        self::print("t - migrate only tables", $cl);
        self::print("i - migrate only indexes", $cl);
        self::print("c - migrate only constraints", $cl);
        self::printLabel("migrate", $cl);

        // migrate
        self::printLabel("sql", $cl);
        self::printMessage("flags - selection additional to be action", $cl);
        self::print("s - show only schemes", $cl);
        self::print("t - show only tables", $cl);
        self::print("i - show only indexes", $cl);
        self::print("c - show only constraints", $cl);
        self::printLabel("sql", $cl);

        self::printTitle("Db Help", $cl);
    }
}
