<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

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

        self::assertInstanceOf(ResponseException::class, $e);
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
