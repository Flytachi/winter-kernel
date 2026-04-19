<?php

namespace Flytachi\Winter\Kernel\Dev\Main;

use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Http\Response\ResponseView;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

#[RequestMapping]
class MainController extends Controller
{

    #[GetMapping]
    public function main(): ResponseEntity
    {
        $this->logger->info('request job -> start');
        TestJob::dispatch();
        $this->logger->info('request job -> end');

        return ResponseEntity::ok("hello");
    }

    #[GetMapping('view')]
    public function view(): ResponseView
    {
        return ResponseView::view('index');
    }
}
