<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\ResponseTimeoutException
 */
class ResponseTimeoutExceptionTest extends TestCase
{
    public function testHasRequestAndResponse(): void
    {
        $req = new Request('GET', '/');
        $res = new Response(504);
        $prev = new \Exception();
        $e = new ResponseTimeoutException('foo', $req, $res, $prev);

        self::assertInstanceOf(ResponseException::class, $e);
        self::assertInstanceOf(ResponseTransferException::class, $e);
        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(ConnectException::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame(504, $e->getCode());
        self::assertSame($prev, $e->getPrevious());
    }
}
