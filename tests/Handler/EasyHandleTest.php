<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\EasyHandle
 */
class EasyHandleTest extends TestCase
{
    public function testEnsuresHandleExists()
    {
        $easy = new EasyHandle();
        unset($easy->handle);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The EasyHandle has been released');
        $easy->handle;
    }

    public function testZeroStringDecodeContentPreservesEncodedHeaders()
    {
        $easy = new EasyHandle();
        $easy->headers = [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: 4',
        ];
        $easy->sink = Utils::streamFor('decoded');
        $easy->options = ['decode_content' => '0'];

        $easy->createResponse();

        self::assertSame('gzip', $easy->response->getHeaderLine('x-encoded-content-encoding'));
        self::assertSame('4', $easy->response->getHeaderLine('x-encoded-content-length'));
        self::assertFalse($easy->response->hasHeader('content-encoding'));
    }
}
