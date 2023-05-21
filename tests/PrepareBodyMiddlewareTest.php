<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class PrepareBodyMiddlewareTest extends TestCase
{
    public function methodProvider()
    {
        $methods = ['GET', 'PUT', 'POST'];
        $bodies = ['Test', ''];
        foreach ($methods as $method) {
            foreach ($bodies as $body) {
                yield [$method, $body];
            }
        }
    }

    /**
     * @dataProvider methodProvider
     */
    public function testAddsContentLengthWhenMissingAndPossible($method, $body)
    {
        $h = new MockHandler([
            static function (RequestInterface $request) use ($body) {
                $length = \strlen($body);
                if ($length > 0) {
                    self::assertEquals($length, $request->getHeaderLine('Content-Length'));
                } else {
                    self::assertFalse($request->hasHeader('Content-Length'));
                }

                return new Response(200);
            },
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request($method, 'http://www.google.com', [], $body), []);
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAddsTransferEncodingWhenNoContentLength()
    {
        $body = FnStream::decorate(Psr7\Utils::streamFor('foo'), [
            'getSize' => static function () {
                return null;
            },
        ]);
        $h = new MockHandler([
            static function (RequestInterface $request) {
                self::assertFalse($request->hasHeader('Content-Length'));
                self::assertSame('chunked', $request->getHeaderLine('Transfer-Encoding'));

                return new Response(200);
            },
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $body), []);
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAddsContentTypeWhenMissingAndPossible()
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));
        $h = new MockHandler([
            static function (RequestInterface $request) {
                self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
                self::assertTrue($request->hasHeader('Content-Length'));

                return new Response(200);
            },
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $bd), []);
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function expectProvider()
    {
        return [
            [true, ['100-Continue']],
            [false, []],
            [10, ['100-Continue']],
            [500000, []],
        ];
    }

    /**
     * @dataProvider expectProvider
     */
    public function testAddsExpect($value, $result)
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));

        $h = new MockHandler([
            static function (RequestInterface $request) use ($result) {
                self::assertSame($result, $request->getHeader('Expect'));

                return new Response(200);
            },
        ]);

        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $bd), [
            'expect' => $value,
        ]);
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testIgnoresIfExpectIsPresent()
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));
        $h = new MockHandler([
            static function (RequestInterface $request) {
                self::assertSame(['Foo'], $request->getHeader('Expect'));

                return new Response(200);
            },
        ]);

        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(
            new Request('PUT', 'http://www.google.com', ['Expect' => 'Foo'], $bd),
            ['expect' => true]
        );
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }
}
