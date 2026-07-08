<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\HandlerClosedException
 */
class HandlerClosedExceptionTest extends TestCase
{
    public function testHasRequest(): void
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new HandlerClosedException('foo', $req, 123, $prev);

        self::assertInstanceOf(TransferException::class, $e);
        self::assertInstanceOf(GuzzleException::class, $e);
        self::assertInstanceOf(ClientExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        self::assertSame(123, $e->getCode());
        self::assertSame($prev, $e->getPrevious());
    }
}
