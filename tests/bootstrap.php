<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flytachi\Winter\Logger\Context\ProcessContext;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Logger\LoggerManager;

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
    channels: ['sys' => $null, 'http' => $null, 'cli' => $null],
));
