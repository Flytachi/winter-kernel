<?php

namespace Main\Services;

interface SendInterface
{
    public function list(): array;
    public function send(): void;
}