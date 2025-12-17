<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\File\XML;

enum ContentType: string
{
    case UNDEFINED = '';
    case JSON = 'application/json';
    case XML = 'application/xml';
    case HTML = 'text/html';
    case TEXT = 'text/plain';

    public function headerFullValue(): string
    {
        return $this->value . $this->getCharset();
    }

    public function getCharset(): string
    {
        return match ($this) {
            self::JSON, self::XML,
            self::HTML, self::TEXT => '; charset=utf-8',
            default => '',
        };
    }

    public function serialize(mixed $content): string
    {
        switch ($this) {
            case self::JSON:
                return json_encode($content, JSON_UNESCAPED_UNICODE);

            case self::XML:
                $arrayData = match (true) {
                    is_array($content) => $content,
                    is_object($content) => json_decode(json_encode($content), true),
                    default => [$content],
                };
                return XML::arrayToXml($arrayData);

            default:
                if (is_string($content) || is_numeric($content) || is_bool($content) || is_null($content)) {
                    return (string) $content;
                } else {
                    return print_r($content, true);
                }
        }
    }
}
