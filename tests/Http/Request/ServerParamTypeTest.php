<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use PHPUnit\Framework\TestCase;

/**
 * `getServerParam()` has to answer for the numeric keys too.
 *
 * It was declared `?string` while both runtimes store several of these as numbers —
 * measured against a live Swoole server, five of the eleven keys it publishes threw a
 * `TypeError` under `strict_types` rather than return: `request_time`,
 * `request_time_float`, `server_port`, `remote_port` and `master_time`. Asking a request
 * for the client's port was enough to fail. PHP does the same under FPM with
 * `REQUEST_TIME` and `REQUEST_TIME_FLOAT`.
 *
 * Widening the return type is the compatible direction: an application that implements
 * {@see \Flytachi\Winter\Kernel\Http\Contracts\HttpRequest} with `?string` still satisfies
 * the wider contract, because return types may be narrowed by an implementation.
 */
final class ServerParamTypeTest extends TestCase
{
    public function test_swoole_numeric_server_values_are_returned_not_thrown(): void
    {
        $raw = new \Swoole\Http\Request();
        $raw->server = [
            'request_method'     => 'GET',
            'server_port'        => 9501,
            'remote_port'        => 54321,
            'request_time'       => 1_754_500_000,
            'request_time_float' => 1_754_500_000.123456,
        ];
        $request = new SwooleRequest($raw);

        self::assertSame('GET', $request->getServerParam('request_method'));
        self::assertSame(9501, $request->getServerParam('server_port'));
        self::assertSame(54321, $request->getServerParam('remote_port'));
        self::assertSame(1_754_500_000, $request->getServerParam('request_time'));
        self::assertSame(1_754_500_000.123456, $request->getServerParam('request_time_float'));
        self::assertNull($request->getServerParam('absent'));
    }

    public function test_fpm_numeric_server_values_are_returned_not_thrown(): void
    {
        $original = $_SERVER;
        $_SERVER['REQUEST_TIME']       = 1_754_500_000;
        $_SERVER['REQUEST_TIME_FLOAT'] = 1_754_500_000.123456;
        $_SERVER['SERVER_PORT']        = 8080;

        try {
            $request = new FpmRequest();

            self::assertSame(1_754_500_000, $request->getServerParam('REQUEST_TIME'));
            self::assertSame(1_754_500_000.123456, $request->getServerParam('REQUEST_TIME_FLOAT'));
            self::assertSame(8080, $request->getServerParam('SERVER_PORT'));
        } finally {
            $_SERVER = $original;
        }
    }

    /**
     * The float must survive intact, because the request deadline is computed from it:
     * casting through a string would round it at PHP's 14-digit `precision`.
     */
    public function test_the_arrival_stamp_keeps_its_precision(): void
    {
        $raw = new \Swoole\Http\Request();
        $raw->server = ['request_time_float' => 1_754_500_000.123456];

        $value = new SwooleRequest($raw)->getServerParam('request_time_float');

        self::assertIsFloat($value);
        self::assertSame(0.0, $value - 1_754_500_000.123456);
    }
}
