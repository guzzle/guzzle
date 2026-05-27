<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\BadResponseException
 */
class BadResponseExceptionTest extends TestCase
{
    public function testHasRequestAndResponse(): void
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $response = new Response();
        $e = new BadResponseException('foo', $req, $response, $prev);
        self::assertInstanceOf(ResponseException::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($response, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame($prev, $e->getPrevious());
    }
}
