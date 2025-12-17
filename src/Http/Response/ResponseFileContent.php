<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\File\XML;
use SimpleXMLElement;

abstract class ResponseFileContent implements ResponseFileContentInterface
{
    protected array $headers = [];
    protected string $resourceType;
    protected mixed $data;
    protected string $fileName;
    protected string $mimeType;
    protected bool $isAttachment;
    protected HttpCode $httpCode;
    protected int $maxAge;

    public function __construct(
        string $resourceType,
        mixed $data,
        string $fileName,
        string $mimeType = 'application/octet-stream',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAgeSeconds = 0,
    ) {
        if (!in_array($resourceType, ['binary', 'txt', 'csv', 'xml', 'json'])) {
            throw new ResponseException("Unsupported resource type: {$resourceType}");
        }
        $this->resourceType = $resourceType;
        $this->httpCode = $httpCode;
        $this->isAttachment = $isAttachment;
        $this->fileName = $fileName;
        $this->mimeType = $mimeType;
        $this->maxAge = $maxAgeSeconds;

        try {
            if ($this->resourceType == 'json') {
                $this->constructJson($data);
            } elseif ($this->resourceType == 'xml') {
                $this->constructXml($data);
            } elseif ($this->resourceType == 'csv') {
                $this->constructCsv($data);
            } else {
                $this->data = print_r($data, true);
            }
        } catch (\Exception $ex) {
            throw new ResponseException($ex->getMessage(), previous: $ex);
        }
    }

    public function defaultHeaders(): array
    {
        $extension = $this->resourceType == 'binary' ? '' : '.' . $this->resourceType;
        return [
            'Content-Type' => $this->mimeType,
            'Content-Disposition' => ($this->isAttachment ? 'attachment' : 'inline')
                . '; filename=' . basename($this->fileName, $extension) . $extension,
            'Cache-Control' => 'public, max-age=' . $this->maxAge . ', must-revalidate',
            'Content-Length' => strlen($this->data),
        ];
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
        return [...$this->defaultHeaders(), ...$this->headers];
    }

    final public function getFileName(): string
    {
        $extension = $this->resourceType == 'binary' ? '' : '.' . $this->resourceType;
        return basename($this->fileName, $extension) . $extension;
    }

    final public function getBody(): string
    {
        return $this->data;
    }

    final protected function constructJson(array|string $data): void
    {
        if (is_array($data)) {
            $this->data = json_encode($data);
        } else {
            $this->data = $data;
        }
    }

    final protected function constructXml(\SimpleXMLElement|\stdClass|array|string|int|bool $data): void
    {
        if ($data instanceof SimpleXMLElement) {
            $this->data = $data->asXML();
        } elseif (is_object($data) || $data instanceof \stdClass) {
            $this->data = XML::arrayToXml(
                json_decode(json_encode($data), true)
            );
        } elseif (is_array($data)) {
            $this->data = XML::arrayToXml($data);
        } else {
            $this->data = XML::arrayToXml([$data]);
        }
    }

    final protected function constructCsv(array $data): void
    {
        $fileBody = fopen('php://temp', 'r+b');
        foreach ($data as $line) {
            fputcsv($fileBody, (array) $line, ",", "\"", "\\");
        }
        rewind($fileBody);
        $this->data = stream_get_contents($fileBody);
        fclose($fileBody);
    }
}
