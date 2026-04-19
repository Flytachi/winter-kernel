<?php

require '../bootstrap.php';


// ==> Kernel -------------------------------

////\Flytachi\Kernel\Src\Http\Header::initHeaders([
////    'Accept-CH' => 'Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform',
////    'Critical-CH' => 'Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform',
////    'Permissions-Policy' => 'ch-ua=(self), ch-ua-mobile=(self), ch-ua-platform=(self)'
////]);
//
//
//\Flytachi\Winter\Kernel\Actuator::use(
//    new \Flytachi\Winter\Kernel\Http\Router(),
//    new \Flytachi\Winter\Kernel\Http\RouteNotFound(),
//);

// ==> Kernel -------------------------------



// ==> K2 -------------------------------

$routing = \Flytachi\Winter\K2\Route\Router::fromScan(
    \Flytachi\Winter\Kernel\Kernel::$pathRoot
);
$routing->handle(
    new \Flytachi\Winter\K2\Http\Adapter\FpmRequest(),
    new \Flytachi\Winter\K2\Http\Adapter\FpmResponse()
);

// ==> K2 -------------------------------