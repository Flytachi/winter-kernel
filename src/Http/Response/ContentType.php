<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\File\XML;

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
            // JSON_THROW_ON_ERROR, because the alternative is worse than an exception:
            // json_encode() answers `false` on malformed UTF-8 — a byte from an external
            // system, a truncated multibyte character in a database column — and this
            // method is declared to return a string. The TypeError that follows says
            // "Return value must be of type string, false returned", which is true and
            // useless: the router turns it into a 500 whose message points at the
            // framework rather than at the encoding of the data. A JsonException says
            // "Malformed UTF-8 characters, possibly incorrectly encoded", which is where
            // to look.
            self::JSON => json_encode(
                $content,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),

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
