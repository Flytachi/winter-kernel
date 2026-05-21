<?php

namespace Main;

use Flytachi\Winter\K2\Http\Request\Validation\ListOf;
use Flytachi\Winter\K2\Http\Request\Validation\Size;

readonly class ListOrder
{
    public function __construct(
        #[ListOf(Order::class)]
        public array $orders,
    ) {
    }
}