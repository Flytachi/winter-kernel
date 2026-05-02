<?php

namespace Main;

use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestBody;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Services\SendInterface;
use Main\Services\SmsSendService;

#[RequestMapping]
class MainController extends Controller
{
    #[Inject(SmsSendService::class)]
    private SendInterface $service;

    #[AuthMiddleware]
    #[PostMapping('test')]
    public function hello(
        #[RequestBody] array $request,
    ): ResponseEntity
    {
        dd($request);
//        $this->service->send();
//        return ResponseEntity::ok(
//            $this->service->list()
//        );
    }

//    #[GetMapping('/test')]
    public function hello2(): ResponseEntity
    {
        return ResponseEntity::ok(
            $this->service->list()
        );
    }
}
