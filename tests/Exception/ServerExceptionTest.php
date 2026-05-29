<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\ServerException
 */
class ServerExceptionTest extends TestCase
{
    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(500);
        $prev = new \Exception();
        $e = new ServerException('foo', $req, $res, $prev);

        self::assertInstanceOf(BadResponseException::class, $e);
        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertTrue($e->hasResponse());
        self::assertSame(500, $e->getCode());
        self::assertSame('foo', $e->getMessage());
        self::assertSame($prev, $e->getPrevious());
    }
}
