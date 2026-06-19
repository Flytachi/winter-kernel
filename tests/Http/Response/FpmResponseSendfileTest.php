<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Response;

use Flytachi\Winter\K2\Http\Adapter\FpmResponse;
use PHPUnit\Framework\TestCase;

final class FpmResponseSendfileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fpm_' . uniqid() . '.txt';
        file_put_contents($this->path, '0123456789abcdefghij'); // 20 bytes
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testSendfileStreamsFullFile(): void
    {
        $out = $this->capture(fn() => (new FpmResponse())->sendfile($this->path));
        self::assertSame('0123456789abcdefghij', $out);
    }

    public function testSendfileWithOffsetAndLength(): void
    {
        $out = $this->capture(fn() => (new FpmResponse())->sendfile($this->path, 5, 5));
        self::assertSame('56789', $out);
    }

    public function testHeadOnlySendfileSuppressesBody(): void
    {
        $out = $this->capture(fn() => (new FpmResponse(true))->sendfile($this->path));
        self::assertSame('', $out);
    }

    public function testSendfileIsNoOpAfterEnd(): void
    {
        $out = $this->capture(function (): void {
            $res = new FpmResponse();
            $res->end('');
            $res->sendfile($this->path);
        });
        self::assertSame('', $out);
    }
}