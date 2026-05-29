<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\TransferException
 */
class TransferExceptionTest extends TestCase
{
    public function testIsGuzzleException()
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new TransferException('foo', $req, 123, $prev);

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertInstanceOf(GuzzleException::class, $e);
        self::assertInstanceOf(ClientExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        self::assertSame(123, $e->getCode());
        self::assertSame($prev, $e->getPrevious());
    }
}
