<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Tests\DeprecationTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\NetworkException
 */
class NetworkExceptionTest extends TestCase
{
    use DeprecationTestTrait;

    public function testHasRequest()
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new NetworkException('foo', $req, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(TransferException::class, $e);
        self::assertInstanceOf(GuzzleException::class, $e);
        self::assertInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        $context = $this->withoutDeprecations(static function () use ($e) {
            return $e->getHandlerContext();
        });
        self::assertSame('bar', $context['foo']);
        self::assertSame($prev, $e->getPrevious());
    }
}
