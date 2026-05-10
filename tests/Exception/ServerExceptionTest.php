<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\ServerException
 */
class ServerExceptionTest extends TestCase
{
    public function testExtendsCorrectHierarchy(): void
    {
        $e = new ServerException('Internal Server Error', new Request('GET', '/'), new Response(500));
        self::assertInstanceOf(BadResponseException::class, $e);
        self::assertInstanceOf(RequestException::class, $e);
    }

    public function testAlwaysHasResponse(): void
    {
        $response = new Response(503);
        $e = new ServerException('Service Unavailable', new Request('GET', '/'), $response);
        self::assertTrue($e->hasResponse());
        self::assertSame($response, $e->getResponse());
    }

    public function testIsCreatedByRequestExceptionCreateFor5xxResponse(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        self::assertInstanceOf(ServerException::class, $e);
    }
}
