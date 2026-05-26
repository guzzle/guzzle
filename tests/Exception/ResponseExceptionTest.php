<?php

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
    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(200);
        $prev = new \Exception();
        $e = new ResponseException('foo', $req, $res, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(RequestException::class, $e);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame('bar', $e->getHandlerContext()['foo']);
        self::assertSame($prev, $e->getPrevious());
    }

    public function testHasResponseIsDeprecated()
    {
        $e = new ResponseException('foo', new Request('GET', '/'), new Response(200));

        $deprecations = self::captureDeprecations(static function () use ($e): void {
            self::assertTrue($e->hasResponse());
        });

        self::assertSame([
            'Since guzzlehttp/guzzle 7.11: GuzzleHttp\\Exception\\ResponseException::hasResponse() is deprecated and will be removed in 8.0. Use instanceof GuzzleHttp\\Exception\\ResponseException instead.',
        ], $deprecations);
    }

    private static function captureDeprecations(callable $callback): array
    {
        $deprecations = [];

        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity !== \E_USER_DEPRECATED) {
                return false;
            }

            $deprecations[] = $message;

            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }
}
