<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\K2\File\XML;

enum ContentType: string
{
    case UNDEFINED = '';
    case JSON      = 'application/json';
    case XML       = 'application/xml';
    case HTML      = 'text/html';
    case TEXT      = 'text/plain';

    public function headerFullValue(): string
    {
        return $this->value . $this->getCharset();
    }

    public function getCharset(): string
    {
        return match ($this) {
            self::JSON, self::XML,
            self::HTML, self::TEXT => '; charset=utf-8',
            default                => '',
        };
    }

    public function serialize(mixed $content): string
    {
        return match ($this) {
            self::JSON => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            self::XML  => XML::arrayToXml(match (true) {
                is_array($content)  => $content,
                is_object($content) => json_decode(json_encode($content), true),
                default             => [$content],
            }),

            default => is_string($content) || is_numeric($content) || is_bool($content) || is_null($content)
                ? (string) $content
                : print_r($content, true),
        };
    }
}
