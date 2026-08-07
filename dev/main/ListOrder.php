<?php

namespace Main;

use Flytachi\Winter\Kernel\Http\Request\Validation\ListOf;
use Flytachi\Winter\Kernel\Http\Request\Validation\Size;

readonly class ListOrder
{
    public function __construct(
        #[ListOf(Order::class)]
        public array $orders,
    ) {
    }
}