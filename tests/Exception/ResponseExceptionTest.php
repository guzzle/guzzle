<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\ResponseException
 */
class ResponseExceptionTest extends TestCase
{
    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(200);
        $prev = new \Exception();
        $e = new ResponseException('foo', $req, $res, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame('bar', $e->getHandlerContext()['foo']);
        self::assertSame($prev, $e->getPrevious());
    }
}
