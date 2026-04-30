<?php

namespace Main;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

class MainController extends Controller
{
    #[Autowired]
    private SmsService $service;

    #[GetMapping]
    public function hello(): ResponseEntity
    {
        S1Job::dispatch();
        return ResponseEntity::ok(
            $this->service->list()
        );
    }
}
