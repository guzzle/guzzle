<?php

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\TooManyRedirectsException
 */
class TooManyRedirectsExceptionTest extends TestCase
{
    public function testHasResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(302);
        $prev = new \Exception();
        $e = new TooManyRedirectsException('foo', $req, $res, $prev, ['foo' => 'bar']);

        self::assertInstanceOf(RequestException::class, $e);
        self::assertNotInstanceOf(ResponseException::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertSame('foo', $e->getMessage());
        self::assertSame('bar', $e->getHandlerContext()['foo']);
        self::assertSame($prev, $e->getPrevious());
    }

    public function testHasResponseIsDeprecated()
    {
        $e = new TooManyRedirectsException('foo', new Request('GET', '/'), new Response(302));

        $deprecations = self::captureDeprecations(static function () use ($e): void {
            self::assertTrue($e->hasResponse());
        });

        self::assertSame([
            'Since guzzlehttp/guzzle 7.11: GuzzleHttp\\Exception\\TooManyRedirectsException::hasResponse() is deprecated and will be removed in 8.0. Use instanceof GuzzleHttp\\Exception\\ResponseException instead.',
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
