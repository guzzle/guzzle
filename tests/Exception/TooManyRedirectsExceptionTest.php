<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\TooManyRedirectsException
 */
class TooManyRedirectsExceptionTest extends TestCase
{
    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(302);
        $prev = new \Exception();
        $e = new TooManyRedirectsException('foo', $req, $res, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(RequestException::class, $e);
        self::assertNotInstanceOf(ResponseException::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame('bar', $e->getHandlerContext()['foo']);
        self::assertSame($prev, $e->getPrevious());
    }
}
