<?php

require_once 'bootstrap.php';

\Flytachi\Winter\Kernel\Actuator::use(
    new \Flytachi\Winter\Console\Core($argv)
);
