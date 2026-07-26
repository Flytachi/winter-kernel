<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flytachi\Winter\Logger\Context\ProcessContext;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Logger\LoggerManager;

// Pin PHP timezone explicitly. CDO::applyDatabaseTimezone() reads
// date_default_timezone_get() during connect; without an explicit setting
// the value is implementation-defined and can produce non-deterministic
// `SET time_zone` queries (which on some MariaDB builds segfaults pdo_mysql).
date_default_timezone_set('UTC');

// Initialise the global LoggerFactory with null-output channels.
// Several modules (e.g. PpaConnectionPool::logger()) call LoggerFactory::getLogger()
// during construction; without a registered manager those tests fail with
// "LoggerFactory is not initialized".
$null = [
    'level'        => 'info',
    'format'       => 'line',
    'output'       => 'null',
    'file_path'    => null,
    'file_max'     => 0,
    'syslog_ident' => 'winter',
];

LoggerFactory::setManager(new LoggerManager(
    contextStorage: new ProcessContext(),
    channels: ['sys' => $null, 'http' => $null],
));

// LoggerFactory's built-in default channel is 'cli'; pin it to 'sys' to match
// Kernel::init() now that 'cli' is no longer a registered channel.
LoggerFactory::setDefaultChannel('sys');
