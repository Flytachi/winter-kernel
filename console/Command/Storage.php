<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

final class Storage extends Cmd
{
    public static string $title = "manage storage folders (init, clean)";
    private string $templatePath;

    public function handle(): void
    {
        self::printTitle("Storage", 34);
        $this->templatePath = dirname(__DIR__) . '/Template/Storage';

        if (count($this->args['flags']) > 0 || count($this->args['arguments']) > 1) {
            $this->resolution();
        } else {
            self::help();
        }

        self::printTitle("Storage", 34);
    }

    private function resolution(): void
    {
        switch ($this->args['arguments'][1] ?? '') {
            case 'init':
                $this->initArg();
                break;
            case 'clean':
                $this->cleanArg();
                break;
            default:
                self::printWarning("Unknown argument '{$this->args['arguments'][1]}'");
                self::printInfo("Run 'call storage --help' to see available commands.");
                break;
        }
    }

    private function initArg(): void
    {
        if (count($this->args['flags']) == 0) {
            $this->storageInit();
            $this->storageCacheInit();
            $this->storageLogInit();
        } else {
            foreach ($this->args['flags'] as $flag) {
                switch ($flag) {
                    case 's':
                        $this->storageInit();
                        break;
                    case 'c':
                        $this->storageCacheInit();
                        break;
                    case 'l':
                        $this->storageLogInit();
                        break;
                    default:
                        self::printWarning("Unknown flag '-{$flag}'");
                        break;
                }
            }
        }
    }

    private function cleanArg(): void
    {
        if (count($this->args['flags']) == 0) {
            $this->storageClean();
            $this->storageCacheClean();
            $this->storageLogClean();
        } else {
            foreach ($this->args['flags'] as $flag) {
                switch ($flag) {
                    case 's':
                        $this->storageClean();
                        break;
                    case 'c':
                        $this->storageCacheClean();
                        break;
                    case 'l':
                        $this->storageLogClean();
                        break;
                    default:
                        self::printWarning("Unknown flag '-{$flag}'");
                        break;
                }
            }
        }
    }

    private function storageInit(): void
    {
        if (!is_dir(Kernel::$pathStorage)) {
            if (mkdir(Kernel::$pathStorage, 0777, true)) {
                copy($this->templatePath . '/gitignoreStorage', Kernel::$pathStorage . '/.gitignore');
                self::printBadge("storage", 'CREATED', 34, 32);
            } else {
                self::printBadge("storage", 'FAILED', 34, 31);
            }
        } else {
            self::printBadge("storage", 'EXISTS', 34, 33);
        }
    }

    private function storageCacheInit(): void
    {
        if (!is_dir(Kernel::$pathStorageCache)) {
            if (mkdir(Kernel::$pathStorageCache, 0777, true)) {
                copy($this->templatePath . '/gitignoreStorageCache', Kernel::$pathStorageCache . '/.gitignore');
                self::printBadge("storage/cache", 'CREATED', 34, 32);
            } else {
                self::printBadge("storage/cache", 'FAILED', 34, 31);
            }
        } else {
            self::printBadge("storage/cache", 'EXISTS', 34, 33);
        }
    }

    private function storageLogInit(): void
    {
        if (!is_dir(Kernel::$pathStorageLog)) {
            if (mkdir(Kernel::$pathStorageLog, 0777, true)) {
                copy($this->templatePath . '/gitignoreStorageLogs', Kernel::$pathStorageLog . '/.gitignore');
                self::printBadge("storage/logs", 'CREATED', 34, 32);
            } else {
                self::printBadge("storage/logs", 'FAILED', 34, 31);
            }
        } else {
            self::printBadge("storage/logs", 'EXISTS', 34, 33);
        }
    }

    private function storageClean(): void
    {
        if (is_dir(Kernel::$pathStorage)) {
            flushDirectory(
                Kernel::$pathStorage,
                Kernel::$pathStorage,
                [
                    str_replace(Kernel::$pathStorage, '', Kernel::$pathStorageCache),
                    str_replace(Kernel::$pathStorage, '', Kernel::$pathStorageLog),
                ],
                ['.gitignore'],
                function ($info) {
                    $label = 'storage/' . ltrim($info['path'], '/');
                    if ($info['status']) {
                        self::printBadge($label, 'DELETED', 34, 32);
                    } else {
                        self::printBadge($label, 'FAILED', 34, 31);
                    }
                }
            );
        } else {
            self::printWarning("Folder 'storage' does not exist.");
        }
    }

    private function storageCacheClean(): void
    {
        if (is_dir(Kernel::$pathStorageCache)) {
            flushDirectory(
                Kernel::$pathStorageCache,
                Kernel::$pathStorageCache,
                [],
                ['.gitignore'],
                function ($info) {
                    $label = 'storage/cache/' . ltrim($info['path'], '/');
                    if ($info['status']) {
                        self::printBadge($label, 'DELETED', 34, 32);
                    } else {
                        self::printBadge($label, 'FAILED', 34, 31);
                    }
                }
            );
        } else {
            self::printWarning("Folder 'storage/cache' does not exist.");
        }
    }

    private function storageLogClean(): void
    {
        if (is_dir(Kernel::$pathStorageLog)) {
            flushDirectory(
                Kernel::$pathStorageLog,
                Kernel::$pathStorageLog,
                [],
                ['.gitignore'],
                function ($info) {
                    $label = 'storage/logs/' . ltrim($info['path'], '/');
                    if ($info['status']) {
                        self::printBadge($label, 'DELETED', 34, 32);
                    } else {
                        self::printBadge($label, 'FAILED', 34, 31);
                    }
                }
            );
        } else {
            self::printWarning("Folder 'storage/logs' does not exist.");
        }
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Storage Help", $cl);

        self::printLabel("Usage", $cl);
        self::print("call storage [command] -[flags]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Commands", $cl);
        self::printBadge('init', 'create storage folders', $cl, 36);
        self::printBadge('clean', 'delete contents of storage folders', $cl, 36);
        self::printLabel("Commands", $cl);

        self::printLabel("Flags", $cl);
        self::printKeyValue("-s", "target: storage", 10, $cl, 36);
        self::printKeyValue("-c", "target: storage/cache", 10, $cl, 36);
        self::printKeyValue("-l", "target: storage/logs", 10, $cl, 36);
        self::printInfo("(no flags = all targets)");
        self::printLabel("Flags", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call storage init");
        self::printInfo("call storage init -s -c");
        self::printInfo("call storage clean -l");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net");

        self::printTitle("Storage Help", $cl);
    }
}
