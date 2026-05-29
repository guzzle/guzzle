<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\NetworkException
 */
class NetworkExceptionTest extends TestCase
{
    public function testHasRequest()
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new NetworkException('foo', $req, $prev);

        self::assertInstanceOf(TransferException::class, $e);
        self::assertInstanceOf(GuzzleException::class, $e);
        self::assertInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        self::assertSame($prev, $e->getPrevious());
    }
}
