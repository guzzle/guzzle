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

    public function testCanCreateCopyWithUpdatedResponse(): void
    {
        $req = new Request('GET', '/');
        $res = new Response(418, [], 'original');
        $newRes = new Response(418, [], 'updated');
        $prev = new \Exception();
        $e = new ResponseException('foo', $req, $res, $prev);

        $new = $e->withResponse($newRes);

        self::assertNotSame($e, $new);
        self::assertSame($req, $new->getRequest());
        self::assertSame($newRes, $new->getResponse());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $new->getMessage());
        self::assertSame(418, $new->getCode());
        self::assertSame($prev, $new->getPrevious());
    }

    public function testCanCreateCopyWithUpdatedResponseAndPreviousException(): void
    {
        $e = new ResponseException('foo', new Request('GET', '/'), new Response(418));
        $newRes = new Response(418);
        $prev = new \Exception();

        $new = $e->withResponse($newRes, $prev);

        self::assertSame($newRes, $new->getResponse());
        self::assertSame(418, $new->getCode());
        self::assertSame($prev, $new->getPrevious());
    }

    public function testCannotCreateCopyWithDifferentStatusCode(): void
    {
        $e = new ResponseException('foo', new Request('GET', '/'), new Response(418));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot replace response with a different status code.');

        $e->withResponse(new Response(200));
    }
}
