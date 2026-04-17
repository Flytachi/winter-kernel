<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Kernel;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
abstract class ExceptionResponseBase implements ResponseExceptionInterface
{
    use ResponseTrait;

    protected HttpCode $httpCode;
    protected \Throwable $throwable;

    public function __construct(\Throwable $throwable)
    {
        $this->throwable = $throwable;
        $this->httpCode = HttpCode::tryFrom((int) $throwable->getCode()) ?: HttpCode::UNKNOWN_ERROR;
    }

    final public function getHttpCode(): HttpCode
    {
        return $this->httpCode;
    }

    public function getBody(): string
    {
        $contentType = AcceptHeaderParser::getBestMatch(
            Header::getHeader('Accept')
        );
        if ($contentType !== ContentType::UNDEFINED) {
            $this->addHeader('Content-Type', $contentType->headerFullValue());
        }
        if (in_array($contentType, [ContentType::JSON, ContentType::XML])) {
            return $contentType->serialize($this->content());
        } else {
            return $contentType->serialize($this->contentText());
        }
    }

    protected function content(): array
    {
        return [
            'code' => $this->throwable->getCode(),
            'message' => $this->throwable->getMessage(),
            ...$this->debugger(),
        ];
    }

    protected function contentText(): string
    {
        if (env('DEBUG', false)) {
            $shortCode = is_numeric($this->throwable->getCode())
                ? (int) $this->throwable->getCode() / 100
                : 0;
            $tColor = match ($shortCode) {
                1 => "00ffff",
                2 => "00ff00",
                3 => "ff00e0",
                4 => "ffff00",
                5 => "ff0000",
                default => "dddddd",
            };

            if (WINTER_STARTUP_TIME !== null) {
                $delta = round(microtime(true) - WINTER_STARTUP_TIME, 3);
                $delta = ($delta < 0.001) ? 0.001 : $delta;
            } else {
                $delta = null;
            }

            $message = [];
            $this->forThrow($message, $this->throwable);

            $result  = '<body style="background-color: #0a0f1f">';
            $result .= '<div style="border: 2px solid #' . $tColor
                . ';border-radius: 7px;padding: 10px;background-color: black;">';
            $result .=    '<div style="display: flex;justify-content: space-between;'
                . 'margin-top: 8px;margin-bottom: 17px">';
            $result .=        '<span style="float: left;font-size: 1.2rem; color: #ffffff;">';
            $result .=            '<span style="color: #' . $tColor . ';font-weight: bold;">['
                . $this->throwable->getCode()
                . '] Winter Debug Message:</span> ' . $this->throwable::class;
            $result .=        '</span>';
            $result .=        '<span style="float: right;font-style: italic;">';
            $result .=            '<span style="color: #adadad">' . date(DATE_ATOM) . '</span> ';
            $result .=            '<span style="color: #00ffff">' . date_default_timezone_get() . '</span>';
            $result .=        '</span>';
            $result .=    '</div>';
            $result .=    '<hr style="border: 1px solid #999999;">';
            $result .=    '<pre style="margin:10px; white-space: pre-wrap; '
                . 'white-space: -moz-pre-wrap;white-space: -o-pre-wrap;word-wrap: break-word;">';
            $result .=      '<span style="color: #' . $tColor . ';font-size: 1.1rem;font-weight: bold;">'
                . $this->throwable->getMessage() . '</span><br>';
            foreach ($message as $msg) {
                $result .=  '<span style="color: #f1f1f1;">' . print_r($msg, true) . '</span><br>';
            }
            $result .=      '<span style="color: #fd2929;font-size: 1.2rem;font-weight: bold;">DETAIL</span><br>';
            $result .=      '<span style="color: #fa5151;">' . print_r($this->throwable, true) . '</span><br>';
            $result .=    '</pre>';
            $result .=    '<hr style="border: 1px solid #999999;">';
            $result .=    '<div style="display: flex;justify-content: space-between;">';
            $result .=        '<span style="float: left;color: #9e9e9e;font-weight: bold;">Memory '
                . bytes(memory_get_usage(), 'MiB') . '</span>';
            $result .=        '<span style="float: right;color: #9e9e9e;font-style: italic;">Time '
                . $delta . '</span>';
            $result .=    '</div>';
            $result .= '</div>';
            $result .= '</body>';
        } else {
            $_error['code'] = $this->throwable->getCode() ?: HttpCode::UNKNOWN_ERROR->value;
            $_error['message'] = $this->throwable->getMessage();
            if (file_exists(Kernel::$pathResource . '/exception/' . $_error['code'] . '.php')) {
                ob_start();
                include Kernel::$pathResource . '/exception/' . $_error['code'] . '.php';
                $result = ob_get_clean();
            } elseif (file_exists(Kernel::$pathResource . '/exception.php')) {
                ob_start();
                include Kernel::$pathResource . '/exception.php';
                $result = ob_get_clean();
            } else {
                $httpMessage = HttpCode::tryFrom($_error['code'])?->message() ?: 'Unknown Error';
                $result = '<!DOCTYPE html><html lang="en">';
                $result .= '<head>';
                $result .=      '<meta charset="utf-8">';
                $result .=      '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
                $result .=      '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
                $result .=      '<link rel="icon" type="image/svg+xml" href="/static/winter/logo.svg">';
                $result .=      "<title>{$_error['code']} {$httpMessage}</title>";
                $result .= '</head>';
                $result .= '<body style="background-color: #0a0f1f;color: #ffffff">';
                $result .=      '<center>';
                $result .=      '<div><img src="/static/winter/logo.svg" alt="logotype" width="80" height="80"></div>';
                $result .=      '<strong style="font-size:21px;"><em>Winter ' . $_error['code'] . ' - '
                    . $httpMessage . '</em></strong>';
                $result .=      '<hr width="50%">';
                $result .=      "<h2 style=\"color:#676980FF\">{$_error['message']}</h2>";
                $result .=      '</center>';
                $result .= '</body>';
            }
        }
        return $result;
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
            ],
            'exception' => [
                'name' => $this->throwable::class,
                'file' => $this->throwable->getFile(),
                'line' => $this->throwable->getLine(),
                'trace' => $this->throwable->getTrace(),
            ]
        ];
    }

    private function forThrow(array &$message, \Throwable $throwable): void
    {
        $previous = $throwable->getPrevious();
        if ($previous) {
            $this->forThrow($message, $previous);
        }
        foreach ($throwable->getTrace() as $key => $value) {
            $ms = "#{$key} ";
            if ($key == 0) {
                $ms .= $value['file'] ?? $throwable->getFile();
                $ms .= ' (' . ($value['line'] ?? $throwable->getLine()) . '): ';
            } else {
                if (isset($value['file'])) {
                    $ms .= $value['file'];
                }
                if (isset($value['line'])) {
                    $ms .= ' (' . $value['line'] . '): ';
                }
            }
            if (isset($value['class'])) {
                $ms .= $value['class'];
            }
            if (isset($value['type'])) {
                $ms .= $value['type'];
            }
            if (isset($value['function'])) {
                $ms .= $value['function'];
            }
            $message[] = $ms;
        }
    }
}
