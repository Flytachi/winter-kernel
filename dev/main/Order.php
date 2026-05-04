<?php

namespace Main;

use Flytachi\Winter\K2\Http\Request\Validation\Size;

readonly class Order
{
    public function __construct(
        #[Size(3)]
        public string $name,
    ) {
    }
}