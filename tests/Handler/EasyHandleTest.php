<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\EasyHandle
 */
class EasyHandleTest extends TestCase
{
    public function testEnsuresHandleExists(): void
    {
        $easy = new EasyHandle();
        unset($easy->handle);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The EasyHandle has been released');
        $easy->handle;
    }

    public function testCreateResponseIgnoresInterim1xxResponses(): void
    {
        $easy = new EasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 103 Early Hints', 'Link: </style.css>; rel=preload'];

        $easy->createResponse();
        self::assertNull($easy->response, 'an interim 1xx must not be stored as the response');

        $easy->headers = ['HTTP/1.1 200 OK', 'Content-Length: 0'];
        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertSame(200, $easy->response->getStatusCode());
    }

    public function testCreateResponseIgnores100Continue(): void
    {
        $easy = new EasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 100 Continue'];

        $easy->createResponse();

        self::assertNull($easy->response);
    }

    public function testCreateResponsePreserves101SwitchingProtocols(): void
    {
        $easy = new EasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 101 Switching Protocols', 'Upgrade: websocket', 'Connection: Upgrade'];

        $easy->createResponse();

        self::assertNotNull($easy->response, '101 is terminal and must be kept as a response');
        self::assertSame(101, $easy->response->getStatusCode());
    }
}
