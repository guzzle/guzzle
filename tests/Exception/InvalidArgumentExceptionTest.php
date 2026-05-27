<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\InvalidArgumentException
 */
class InvalidArgumentExceptionTest extends TestCase
{
    public function testIsGuzzleException()
    {
        $prev = new \Exception();
        $e = new InvalidArgumentException('foo', 123, $prev);

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        self::assertInstanceOf(GuzzleException::class, $e);
        self::assertInstanceOf(ClientExceptionInterface::class, $e);
        self::assertSame('foo', $e->getMessage());
        self::assertSame(123, $e->getCode());
        self::assertSame($prev, $e->getPrevious());
    }
}
