<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\Exception\ExceptionHeader;
use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Exception\ExceptionHeaderTrait;
use Psr\Log\LogLevel;

/**
 * Throwable HTTP response exception.
 *
 * Throw from anywhere (controller, service, middleware) — the Router
 * catches it and sends a proper HTTP response automatically.
 *
 * Examples:
 *   throw new ResponseException('Not found', HttpCode::NOT_FOUND);
 *   ResponseException::throw('Forbidden', HttpCode::FORBIDDEN);
 */
class ResponseException extends \RuntimeException implements ExceptionLogLevel, ExceptionHeader
{
    use ExceptionTrait;
    use ExceptionHeaderTrait;

    protected $code = HttpCode::BAD_REQUEST->value;

    /**
     * `WARNING` for a client error, `ERROR` for a server one — the same split the rest
     * of the kernel uses ({@see \Flytachi\Winter\Kernel\Exception\ClientError} is
     * `WARNING`, {@see \Flytachi\Winter\Kernel\Exception\ServerError} is `ERROR`).
     *
     * This class is thrown with whatever status the caller passes, so a flat level was
     * right for the 4xx it mostly carries and wrong for the 5xx it also can: a request
     * that timed out or hit an upstream failure was filed next to a plain 404, in the
     * stream nobody pages on.
     *
     * A status below 400 keeps `WARNING` too — an exception is not the normal way to
     * return one, and saying so quietly is enough.
     */
    public function getLogLevel(): string
    {
        return $this->getCode() >= 500 ? LogLevel::ERROR : LogLevel::WARNING;
    }
}
