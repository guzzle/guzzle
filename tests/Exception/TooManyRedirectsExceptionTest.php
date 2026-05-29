<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Tests\DeprecationTestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * @covers \GuzzleHttp\Exception\TooManyRedirectsException
 */
class TooManyRedirectsExceptionTest extends TestCase
{
    use DeprecationTestTrait;

    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(302);
        $prev = new \Exception();
        $e = new TooManyRedirectsException('foo', $req, $res, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertTrue($e->hasResponse());
        self::assertSame('foo', $e->getMessage());
        $context = $this->withoutDeprecations(static function () use ($e) {
            return $e->getHandlerContext();
        });
        self::assertSame('bar', $context['foo']);
        self::assertSame($prev, $e->getPrevious());
    }
}
