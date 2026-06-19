<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\File\XML;
use SimpleXMLElement;

/**
 * File / download response — port of ResponseFileContent + ResponseContent.
 *
 * Factory methods:
 *   ResponseFile::binary($data, 'report.bin')
 *   ResponseFile::txt($data, 'log.txt')
 *   ResponseFile::csv($rows, 'export.csv')
 *   ResponseFile::json($array, 'data.json')
 *   ResponseFile::xml($data, 'feed.xml')
 *   ResponseFile::file('/abs/path/to/file.pdf')
 *
 * Optional flags:
 *   ->attachment()          — force Content-Disposition: attachment (download dialog)
 *   ->inline()              — Content-Disposition: inline (render in browser)
 *   ->maxAge(3600)          — Cache-Control: public, max-age=3600
 */
class ResponseFile implements Sendable
{
    use FileResponseHeaders;

    private string $body;
    private HttpCode $httpCode;
    private string $fileName;
    private string $mimeType;

    private function __construct(
        string $body,
        string $fileName,
        string $mimeType,
        bool $isAttachment,
        HttpCode $httpCode,
        int $maxAge,
    ) {
        $this->body         = $body;
        $this->fileName     = $fileName;
        $this->mimeType     = $mimeType;
        $this->isAttachment = $isAttachment;
        $this->httpCode     = $httpCode;
        $this->maxAge       = $maxAge;
    }

    // ── Factory methods ───────────────────────────────────────────────────────

    public static function binary(
        mixed $data,
        string $fileName,
        string $mimeType = 'application/octet-stream',
        bool $isAttachment = true,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): static {
        return new static(print_r($data, true), $fileName, $mimeType, $isAttachment, $httpCode, $maxAge);
    }

    public static function txt(
        mixed $data,
        string $fileName,
        string $mimeType = 'text/plain',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): static {
        return new static((string) $data, $fileName, $mimeType, $isAttachment, $httpCode, $maxAge);
    }

    public static function csv(
        array $rows,
        string $fileName,
        string $mimeType = 'text/csv',
        bool $isAttachment = true,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): static {
        return new static(self::buildCsv($rows), $fileName, $mimeType, $isAttachment, $httpCode, $maxAge);
    }

    public static function json(
        array|string $data,
        string $fileName,
        string $mimeType = 'application/json',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): static {
        $body = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data;
        return new static($body, $fileName, $mimeType, $isAttachment, $httpCode, $maxAge);
    }

    public static function xml(
        SimpleXMLElement|\stdClass|array|string|int|bool $data,
        string $fileName,
        string $mimeType = 'application/xml',
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): static {
        return new static(self::buildXml($data), $fileName, $mimeType, $isAttachment, $httpCode, $maxAge);
    }

    /**
     * Build a ResponseFile from an existing file path.
     * Detects MIME type automatically.
     */
    public static function file(
        string $filePath,
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): static {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $fileName = basename($filePath);
        $mime     = mime_content_type($filePath) ?: 'application/octet-stream';
        $body     = file_get_contents($filePath);

        return new static($body, $fileName, $mime, $isAttachment, $httpCode, $maxAge);
    }

    // ── Sendable ──────────────────────────────────────────────────────────────

    public function send(HttpResponse $response, HttpRequest $request): void
    {
        $response->status($this->httpCode->value);
        $this->writeFileHeaders($response, $this->mimeType, $this->fileName, mb_strlen($this->body, '8bit'));
        $response->end($this->body);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function buildCsv(array $rows): string
    {
        $tmp = fopen('php://temp', 'r+b');
        foreach ($rows as $row) {
            fputcsv($tmp, (array) $row, ',', '"', '\\');
        }
        rewind($tmp);
        $csv = stream_get_contents($tmp);
        fclose($tmp);
        return $csv;
    }

    private static function buildXml(mixed $data): string
    {
        if ($data instanceof SimpleXMLElement) {
            return $data->asXML();
        }
        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }
        if (!is_array($data)) {
            $data = [$data];
        }
        return XML::arrayToXml($data);
    }
}
