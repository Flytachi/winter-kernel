<?php

use Flytachi\Winter\Thread\Runnable;

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Flytachi\Winter\Kernel\Kernel::init(__DIR__);

\Flytachi\Winter\Kernel\Actuator::use(
    new \Flytachi\Winter\Console\Core($argv)
);
