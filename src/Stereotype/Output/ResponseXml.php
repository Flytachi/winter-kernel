<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype\Output;

use Flytachi\Winter\Kernel\Http\Response\ContentType;
use Flytachi\Winter\Kernel\Http\Response\ResponseBase;

class ResponseXml extends ResponseBase
{
    public function getBody(): string
    {
        $contentType = ContentType::XML;
        $this->addHeader('Content-Type', $contentType->headerFullValue());
        return $contentType->serialize($this->content);
    }
}
