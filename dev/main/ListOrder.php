<?php

namespace Main;

use Flytachi\Winter\K2\Http\Request\Validation\ArrayOf;
use Flytachi\Winter\K2\Http\Request\Validation\Size;

readonly class ListOrder
{
    public function __construct(
        #[ArrayOf(Order::class)]
        public array $orders,
    ) {
    }
}