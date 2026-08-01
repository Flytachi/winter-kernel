<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\Exception\ExceptionHeader;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;

/**
 * Default exception response — implements ResponseExceptionInterface.
 *
 * In DEBUG mode (env DEBUG=true): rich HTML page with stack trace, or JSON with trace.
 * In production: minimal HTML error page, or JSON with message only.
 *
 * Extend this class and add #[AdviceException] to create custom exception handlers
 * (Spring's @ControllerAdvice pattern). ExceptionWrapper will discover them automatically.
 */
class ExceptionResponseBase implements ResponseExceptionInterface
{
    use ResponseTrait;

    protected HttpCode $httpCode;
    protected \Throwable $throwable;

    public function __construct(\Throwable $throwable)
    {
        $this->throwable = $throwable;
        $this->httpCode  = HttpCode::tryFrom((int) $throwable->getCode())
            ?: HttpCode::INTERNAL_SERVER_ERROR;

        if ($throwable instanceof ExceptionHeader) {
            foreach ($throwable->getExtraHeaders() as $name => $value) {
                $this->addHeader($name, $value);
            }
        }
    }

    final public function getHttpCode(): HttpCode
    {
        return $this->httpCode;
    }

    public function getBody(): string
    {
        $contentType = AcceptHeaderParser::getBestMatch(Header::get('Accept'));

        if ($contentType === ContentType::HTML) {
            $this->addHeader('Content-Type', $contentType->headerFullValue());
            return $this->contentHtml();
        }

        if ($contentType !== ContentType::XML) {
            $contentType = ContentType::JSON;
        }

        $this->addHeader('Content-Type', $contentType->headerFullValue());
        return $contentType->serialize($this->contentData());
    }

    // ── Overrideable content builders ─────────────────────────────────────────

    protected function contentData(): array
    {
        $data = [
            'code'    => $this->throwable->getCode(),
            'message' => $this->throwable->getMessage(),
        ];

        $errorValidation = $this->validationRequests();
        if (!empty($errorValidation)) {
            $data['errors'] = $errorValidation;
        }

        return array_merge($data, $this->debugData());
    }

    protected function contentHtml(): string
    {
        if (env('DEBUG', false)) {
            return $this->debugHtml();
        }

        $code        = $this->httpCode->value;
        $httpMessage = $this->httpCode->message();
        $message     = htmlspecialchars($this->throwable->getMessage(), ENT_QUOTES);

        $logo = self::logo();

        return <<<HTML
            <!DOCTYPE html><html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$code} {$httpMessage}</title>
            </head>
            <body style="background-color:#0a0f1f;color:#ffffff;font-family:sans-serif">
                <center>
                    <div>{$logo}</div>
                    <strong style="font-size:21px;"><em>Winter {$code} — {$httpMessage}</em></strong>
                    <hr width="50%">
                    <h2 style="color:#676980FF">{$message}</h2>
                </center>
            </body></html>
            HTML;
    }

    /**
     * The mark, inlined rather than linked.
     *
     * This page is what a visitor sees when the application is already failing, so it
     * must not depend on the application being configured correctly: static serving is
     * opt-in, and a linked asset would simply 404. Inlining the element (rather than a
     * `data:` URI) also keeps it working under a strict `img-src` policy.
     */
    private static function logo(): string
    {
        return <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 336 264" width="80" height="80" role="img" aria-label="Winter">
              <g transform="translate(20, 20)">
                <polygon points="20,7 48,7 109,190 68,204 27,27" fill="#E6F1FB" stroke="#185FA5" stroke-width="6" stroke-linejoin="round"/>
                <polygon points="68,204 109,190 150,68 177,68 129,211 95,211" fill="#B5D4F4" stroke="#185FA5" stroke-width="6" stroke-linejoin="round"/>
                <polygon points="129,211 177,68 204,68 177,211 150,211" fill="#E6F1FB" stroke="#185FA5" stroke-width="6" stroke-linejoin="round"/>
                <polygon points="150,211 177,211 231,68 258,68 204,190 163,211" fill="#B5D4F4" stroke="#185FA5" stroke-width="6" stroke-linejoin="round"/>
                <polygon points="204,190 245,14 286,14 286,27 245,204 204,204" fill="#E6F1FB" stroke="#185FA5" stroke-width="6" stroke-linejoin="round"/>
              </g>
            </svg>
            SVG;
    }

    final protected function validationRequests(): array
    {
        if ($this->throwable instanceof ValidationException) {
            return $this->throwable->getErrors();
        }
        return [];
    }

    // ── Debug helpers ─────────────────────────────────────────────────────────

    final protected function debugData(): array
    {
        if (!env('DEBUG', false)) {
            return [];
        }

        $memory = memory_get_usage();
        $debug  = [
            'date'     => date(DATE_ATOM),
            'timezone' => date_default_timezone_get(),
            'sapi'     => PHP_SAPI,
            'memory'   => round($memory / 1024, 2) . ' KB',
        ];

        $start = \Flytachi\Winter\Base\Runtime::isSwooleCoroutine()
            ? (\Swoole\Coroutine::getContext()['__request_start'] ?? null)
            : (defined('WINTER_STARTUP_TIME') ? WINTER_STARTUP_TIME : null);
        if ($start !== null) {
            $debug['time'] = max(round(microtime(true) - $start, 3), 0.001);
        }

        return [
            'debug'     => $debug,
            'exception' => [
                'name'  => $this->throwable::class,
                'file'  => $this->throwable->getFile(),
                'line'  => $this->throwable->getLine(),
                'trace' => $this->throwable->getTrace(),
            ],
        ];
    }

    private function debugHtml(): string
    {
        $code  = $this->throwable->getCode();
        $short = is_numeric($code) ? (int) ((int) $code / 100) : 0;
        $color = match ($short) {
            1 => '00ffff', 2 => '00ff00', 3 => 'ff00e0',
            4 => 'ffff00', 5 => 'ff0000', default => 'dddddd',
        };

        $start = \Flytachi\Winter\Base\Runtime::isSwooleCoroutine()
            ? (\Swoole\Coroutine::getContext()['__request_start'] ?? null)
            : (defined('WINTER_STARTUP_TIME') ? WINTER_STARTUP_TIME : null);
        $delta = $start !== null ? max(round(microtime(true) - $start, 3), 0.001) : null;

        $trace = [];
        $this->collectTrace($trace, $this->throwable);

        $traceHtml = implode('<br>', array_map('htmlspecialchars', $trace));
        $detail    = htmlspecialchars(print_r($this->throwable, true), ENT_QUOTES);
        $msg       = htmlspecialchars($this->throwable->getMessage(), ENT_QUOTES);
        $class     = htmlspecialchars($this->throwable::class, ENT_QUOTES);
        $memory    = round(memory_get_usage() / 1048576, 2) . ' MB';

        return <<<HTML
            <body style="background-color:#0a0f1f">
            <div style="border:2px solid #{$color};border-radius:7px;padding:10px;background-color:black">
                <div style="display:flex;justify-content:space-between;margin:8px 0 17px">
                    <span style="font-size:1.2rem;color:#fff">
                        <span style="color:#{$color};font-weight:bold">[{$code}] Winter Debug: </span>{$class}
                    </span>
                    <span style="font-style:italic;color:#adadad">
                        {$this->throwable->getFile()}:{$this->throwable->getLine()}
                    </span>
                </div>
                <hr style="border:1px solid #999">
                <pre style="margin:10px;white-space:pre-wrap;word-wrap:break-word">
                    <span style="color:#{$color};font-size:1.1rem;font-weight:bold">{$msg}</span><br>
                    <span style="color:#f1f1f1">{$traceHtml}</span><br>
                    <span style="color:#fd2929;font-size:1.2rem;font-weight:bold">DETAIL</span><br>
                    <span style="color:#fa5151">{$detail}</span>
                </pre>
                <hr style="border:1px solid #999">
                <div style="display:flex;justify-content:space-between">
                    <span style="color:#9e9e9e;font-weight:bold">Memory {$memory}</span>
                    <span style="color:#9e9e9e;font-style:italic">Time {$delta}</span>
                </div>
            </div>
            </body>
            HTML;
    }

    private function collectTrace(array &$out, \Throwable $e): void
    {
        if ($prev = $e->getPrevious()) {
            $this->collectTrace($out, $prev);
        }
        foreach ($e->getTrace() as $i => $f) {
            $line = "#{$i} ";
            if ($i === 0) {
                $line .= ($f['file'] ?? $e->getFile()) . ' (' . ($f['line'] ?? $e->getLine()) . '): ';
            } else {
                if (isset($f['file'])) {
                    $line .= $f['file'];
                }
                if (isset($f['line'])) {
                    $line .= ' (' . $f['line'] . '): ';
                }
            }
            if (isset($f['class'])) {
                $line .= $f['class'];
            }
            if (isset($f['type'])) {
                $line .= $f['type'];
            }
            if (isset($f['function'])) {
                $line .= $f['function'];
            }
            $out[] = $line;
        }
    }
}
