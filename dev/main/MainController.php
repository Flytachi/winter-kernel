<?php

namespace Main;

use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\K2\Concurrent\Executors;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestBody;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Flytachi\Winter\Logger\Log;
use Main\Services\SendInterface;
use Main\Services\SmsSendService;

#[RequestMapping]
class MainController extends Controller
{
    #[Inject(SmsSendService::class)]
    private SendInterface $service;

    #[GetMapping]
    public function test(): mixed
    {
        Executors::common()->execute(function () {
            sleep(1);
            Log::info("agu agu");
            $this->service->send();
        });

        $t= Executors::common()->submit(function () {
            sleep(2);
            Log::info("submit");
            return $this->service->list();
        });
        dd(
            $t->get()
        );
    }

    #[AuthMiddleware]
    #[PostMapping('test')]
    public function hello(
        #[RequestParam('id'), Positive] int $id,
        #[RequestBody, Valid] Order ...$orders,
    ): ResponseEntity {
        dd($orders);
//        $this->service->send();
//        return ResponseEntity::ok(
//            $this->service->list()
//        );
    }

//    #[PostMapping('/test2/{id}')]
    public function hello2(#[PathVariable('id'), Positive] int $id): ResponseEntity
    {
//        dd($id);
        return ResponseEntity::ok(
            $this->service->list()
        );
    }
}
