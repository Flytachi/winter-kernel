<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Flytachi\Winter\Kernel\Kernel::init(__DIR__);

\Flytachi\Winter\Kernel\Actuator::use(
//    new \Flytachi\Winter\Kernel\Http\Health\Health(
//        indicatorClass: \Flytachi\Winter\Kernel\Http\Health\HealthIndicator::class,
//        middlewareClass: \Flytachi\Winter\Kernel\Stereotype\Middleware\SecurityMiddleware::class,
//    ),
//    new \Flytachi\Winter\Kernel\Http\PluginRouter(),
    new \Flytachi\Winter\Kernel\Http\Router()
);
