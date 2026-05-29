<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\ConnectException
 */
class ConnectExceptionTest extends TestCase
{
    public function testHasRequest()
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new ConnectException('foo', $req, $prev);
        self::assertInstanceOf(NetworkException::class, $e);
        self::assertInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        self::assertSame($prev, $e->getPrevious());
    }
}
