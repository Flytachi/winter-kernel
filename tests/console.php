<?php

use Flytachi\Winter\Thread\Runnable;

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Flytachi\Winter\Kernel\Kernel::init(__DIR__);

//\Flytachi\Winter\Kernel\Actuator::use(
//    new \Flytachi\Winter\Console\Core($argv)
//);


$th = (new \Flytachi\Winter\Thread\Thread(
    new class implements \Flytachi\Winter\Thread\Runnable {
        public function run(): void
        {
            \Flytachi\Winter\Kernel\Kernel::init(__DIR__);
            // -----

            \Flytachi\Winter\Base\Log\Log::info('start');
            for ($i = 0; $i < 10; $i++) {
                \Flytachi\Winter\Base\Log\Log::info('ping ' . $i);
            }
            \Flytachi\Winter\Base\Log\Log::info('end');
        }
    }
))->start(\Flytachi\Winter\Kernel\Kernel::$pathStorage . '/debug.txt');
