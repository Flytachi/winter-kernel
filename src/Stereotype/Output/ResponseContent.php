<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype\Output;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Response\ResponseFileContent;

class ResponseContent extends ResponseFileContent
{
    public static function binary(
        mixed $data,
        string $fileName,
        string $mimeType = 'application/octet-stream',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0
    ): static {
        return new static('binary', $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAgeSeconds);
    }

    public static function txt(
        mixed $data,
        string $fileName,
        string $mimeType = 'text/plain',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0
    ): static {
        return new static('txt', $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAgeSeconds);
    }

    public static function csv(
        array $data,
        string $fileName,
        string $mimeType = 'text/csv',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0
    ): static {
        return new static('csv', $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAgeSeconds);
    }

    public static function json(
        array|string $data,
        string $fileName,
        string $mimeType = 'application/json',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0
    ): static {
        return new static('json', $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAgeSeconds);
    }

    public static function xml(
        \SimpleXMLElement|\stdClass|array|string|int|bool $data,
        string $fileName,
        string $mimeType = 'application/xml',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0
    ): static {
        return new static('xml', $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAgeSeconds);
    }

    public static function file(
        string $filePath,
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0
    ): static {
        $fileName = basename($filePath);
        $mime = getimagesize($filePath);
        $mimeType = $mime['mime'] ?: 'application/octet-stream';
        $data = file_get_contents($filePath);
        return new static('binary', $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAgeSeconds);
    }
}
