<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\ClientException
 */
class ClientExceptionTest extends TestCase
{
    public function testExtendsCorrectHierarchy(): void
    {
        $e = new ClientException('Bad Request', new Request('GET', '/'), new Response(400));
        self::assertInstanceOf(BadResponseException::class, $e);
        self::assertInstanceOf(RequestException::class, $e);
    }

    public function testAlwaysHasResponse(): void
    {
        $response = new Response(404);
        $e = new ClientException('Not Found', new Request('GET', '/'), $response);
        self::assertTrue($e->hasResponse());
        self::assertSame($response, $e->getResponse());
    }

    public function testIsCreatedByRequestExceptionCreateFor4xxResponse(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(400));
        self::assertInstanceOf(ClientException::class, $e);
    }
}
