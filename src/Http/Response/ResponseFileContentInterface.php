<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\HttpCode;

interface ResponseFileContentInterface
{
    public function defaultHeaders(): array;
    public function addHeader(string $key, string $value): void;
    public function getHttpCode(): HttpCode;
    public function getHeader(): array;
    public function getBody(): string;
    public function getFileName(): string;
}
