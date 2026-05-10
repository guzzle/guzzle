<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\TooManyRedirectsException
 */
class TooManyRedirectsExceptionTest extends TestCase
{
    public function testExtendsCorrectHierarchy(): void
    {
        $e = new TooManyRedirectsException('Too many redirects', new Request('GET', '/'));
        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(TransferException::class, $e);
    }

    public function testCanBeConstructedWithoutResponse(): void
    {
        $request = new Request('GET', '/');
        $e = new TooManyRedirectsException('Too many redirects', $request);
        self::assertSame($request, $e->getRequest());
        self::assertFalse($e->hasResponse());
        self::assertNull($e->getResponse());
    }

    public function testCanBeConstructedWithResponse(): void
    {
        $request = new Request('GET', '/');
        $response = new Response(302);
        $e = new TooManyRedirectsException('Too many redirects', $request, $response);
        self::assertTrue($e->hasResponse());
        self::assertSame($response, $e->getResponse());
    }
}
