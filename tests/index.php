<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Flytachi\Winter\Kernel\Kernel::init(__DIR__);

\Flytachi\Winter\Kernel\Actuator::use(
//    new \Flytachi\Kernel\Src\Health\Health(
//        indicatorClass: \Flytachi\Kernel\Src\Health\HealthIndicator::class,
//        middlewareClass: \Flytachi\Kernel\Src\Stereotype\Middleware\SecurityMiddleware::class,
//    ),
    new \Flytachi\Winter\Kernel\Http\Router()
);
