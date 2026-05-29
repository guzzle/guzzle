<?php

declare(strict_types=1);

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
    public function testCarriesRequestAndResponse(): void
    {
        $req = new Request('GET', '/');
        $res = new Response(418);
        $prev = new \Exception();
        $e = new ResponseException('foo', $req, $res, $prev);

        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame(418, $e->getCode());
        self::assertSame($prev, $e->getPrevious());
    }
}
