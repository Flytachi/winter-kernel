<?php

namespace Main;

use Flytachi\Winter\Kernel\Http\Request\K1ValidationTrait;
use Flytachi\Winter\Kernel\Http\Request\Validation\Size;
use Flytachi\Winter\Kernel\Localization\Locale;

readonly class Order
{
    use K1ValidationTrait;

    public function __construct(
        #[Size(3, message: '{order.name_size_error}')]
        public string $name,
    ) {
    }
}