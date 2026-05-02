<?php

namespace Main;

use DateTime;
use DateTimeImmutable;
use Flytachi\Winter\Base\Tool;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestFile;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestHeader;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
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
    #[GetMapping('test')]
    #[PostMapping('test')]
    public function hello(
        #[RequestFile] array $image,
    ): ResponseEntity
    {
        dd($image);
        $this->service->send();
        return ResponseEntity::ok(
            $this->service->list()
        );
    }

//    #[GetMapping('/test')]
    public function hello2(): ResponseEntity
    {
        return ResponseEntity::ok(
            $this->service->list()
        );
    }
}
