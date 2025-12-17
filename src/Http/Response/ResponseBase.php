<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Base\HttpCode;

abstract class ResponseBase implements ResponseInterface
{
    protected array $headers = [];
    protected mixed $content;
    protected HttpCode $httpCode;

    public function __construct(mixed $content, HttpCode $httpCode = HttpCode::OK)
    {
        $this->content = $content;
        $this->httpCode = $httpCode;
    }

    final public function addHeader(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    final public function getHttpCode(): HttpCode
    {
        return $this->httpCode;
    }

    final public function getHeader(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        $contentType = AcceptHeaderParser::getBestMatch(
            Header::getHeader('Accept')
        );
        if ($contentType !== ContentType::UNDEFINED) {
            $this->addHeader('Content-Type', $contentType->headerFullValue());
        }
        return $contentType->serialize($this->content);
    }

    final protected function debugger(): array
    {
        if (!env('DEBUG', false)) {
            return [];
        }

        $delta = round(microtime(true) - WINTER_STARTUP_TIME, 3);
        $memory = memory_get_usage();
        return [
            'debug' => [
                'time' => ($delta < 0.001) ? 0.001 : $delta,
                'date' => date(DATE_ATOM),
                'timezone' => date_default_timezone_get(),
                'sapi' => PHP_SAPI,
                'memory' => bytes($memory, ($memory >= 1048576 ? 'MiB' : 'KiB')),
            ]
        ];
    }
}
