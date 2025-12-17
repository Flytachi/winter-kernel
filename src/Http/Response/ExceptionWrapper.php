<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\File\XML;
use Flytachi\Winter\Kernel\Kernel;

abstract class ExceptionWrapper
{
    private static $instance = self::class;
    private static $headers = [];

    public static function getHeader(): array
    {
        return self::$headers;
    }

    final public static function addHeader(string $key, string $value): void
    {
        self::$headers[$key] = $value;
    }

    public static function getBody(\Throwable $throwable): string
    {
        $contentType = AcceptHeaderParser::getBestMatch(
            Header::getHeader('Accept')
        );
        if ($contentType !== ContentType::UNDEFINED) {
            self::addHeader('Content-Type', $contentType->headerFullValue());
        }
        return match ($contentType) {
            ContentType::JSON => self::constructJson($throwable),
            ContentType::XML => self::constructXml($throwable),
            default => self::constructDefault($throwable)
        };
    }

    final public static function wrapHeader(): array
    {
        /** @var ExceptionWrapper $newInstance */
        $newInstance = self::wrapperInstance();
        return $newInstance::getHeader();
    }

    final public static function wrapBody(\Throwable $throwable): string
    {
        /** @var ExceptionWrapper $newInstance */
        $newInstance = self::wrapperInstance();
        return $newInstance::getBody($throwable);
    }

    final protected static function constructJson(\Throwable $throwable): string
    {
        $context = [
            'code' => $throwable->getCode(),
            'message' => $throwable->getMessage()
        ];

        if (env('DEBUG', false)) {
            $delta = round(microtime(true) - WINTER_STARTUP_TIME, 3);
            $memory = memory_get_usage();

            $context['debug'] = [
                'time' => ($delta < 0.001) ? 0.001 : $delta,
                'date' => date(DATE_ATOM),
                'timezone' => date_default_timezone_get(),
                'sapi' => PHP_SAPI,
                'memory' => bytes($memory, ($memory >= 1048576 ? 'MiB' : 'KiB')),
            ];
            $context['exception'] = [
                'name' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => $throwable->getTrace(),
            ];
        }

        return json_encode($context);
    }

    final protected static function constructXml(\Throwable $throwable): string
    {
        $context = [
            'code' => $throwable->getCode(),
            'message' => $throwable->getMessage()
        ];

        if (env('DEBUG', false)) {
            $delta = round(microtime(true) - WINTER_STARTUP_TIME, 3);
            $memory = memory_get_usage();

            $context['debug'] = [
                'time' => ($delta < 0.001) ? 0.001 : $delta,
                'date' => date(DATE_ATOM),
                'timezone' => date_default_timezone_get(),
                'sapi' => PHP_SAPI,
                'memory' => bytes($memory, ($memory >= 1048576 ? 'MiB' : 'KiB')),
            ];
            $context['exception'] = [
                'name' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => $throwable->getTrace(),
            ];
        }

        return XML::arrayToXml($context);
    }

    final protected static function constructDefault(\Throwable $throwable): string
    {
        if (env('DEBUG', false)) {
            $tColor = match ((int)($throwable->getCode() / 100)) {
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
            self::forThrow($message, $throwable);

            $result  = '<body style="background-color: #0a0f1f">';
            $result .= '<div style="border: 2px solid #' . $tColor
                . ';border-radius: 7px;padding: 10px;background-color: black;">';
            $result .=    '<div style="display: flex;justify-content: space-between;'
                . 'margin-top: 8px;margin-bottom: 17px">';
            $result .=        '<span style="float: left;font-size: 1.2rem; color: #ffffff;">';
            $result .=            '<span style="color: #' . $tColor . ';font-weight: bold;">[' . $throwable->getCode()
                . '] Extra Debug Message:</span> ' . $throwable::class;
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
                . $throwable->getMessage() . '</span><br>';
            foreach ($message as $msg) {
                $result .=  '<span style="color: #f1f1f1;">' . print_r($msg, true) . '</span><br>';
            }
            $result .=      '<span style="color: #fd2929;font-size: 1.2rem;font-weight: bold;">DETAIL</span><br>';
            $result .=      '<span style="color: #fa5151;">' . print_r($throwable, true) . '</span><br>';
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
            $_error['code'] = $throwable->getCode() ?: HttpCode::UNKNOWN_ERROR->value;
            $_error['message'] = $throwable->getMessage();
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
                $result .=      "<title>{$_error['code']} {$httpMessage}</title>";
                $result .= '</head>';
                $result .= '<body style="background-color: #0a0f1f;color: #ffffff">';
                $result .=      '<center>';
                $result .=          '<strong style="font-size:21px;"><em>Extra ' . $_error['code'] . ' - '
                    . $httpMessage . '</em></strong>';
                $result .=          '<hr width="50%">';
                $result .=          "<h2 style=\"color:#676980FF\">{$_error['message']}</h2>";
                $result .=      '</center>';
                $result .= '</body>';
            }
        }
        return $result;
    }

    private static function forThrow(array &$message, \Throwable $throwable): void
    {
        $previous = $throwable->getPrevious();
        if ($previous) {
            self::forThrow($message, $previous);
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

    private static function wrapperInstance(): string
    {
        if (self::$instance === self::class) {
            foreach (get_declared_classes() as $class) {
                if (is_subclass_of($class, self::class)) {
                    self::$instance = $class;
                    break;
                }
            }
        }
        return self::$instance;
    }
}
