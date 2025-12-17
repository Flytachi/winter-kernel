<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Flytachi\Winter\Kernel\Kernel::init(__DIR__);

\Flytachi\Winter\Kernel\Actuator::use(
    new \Flytachi\Winter\Kernel\Console\Core($argv)
);
