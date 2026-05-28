<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\TimeoutException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\NetworkTimeoutException
 */
class NetworkTimeoutExceptionTest extends TestCase
{
    public function testHasRequest(): void
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new NetworkTimeoutException('foo', $req, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(NetworkException::class, $e);
        self::assertInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertInstanceOf(TimeoutException::class, $e);
        self::assertNotInstanceOf(ConnectException::class, $e);
        self::assertNotInstanceOf(ConnectTimeoutException::class, $e);
        self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        self::assertSame('bar', $e->getHandlerContext()['foo']);
        self::assertSame($prev, $e->getPrevious());
    }
}
