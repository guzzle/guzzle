<?php

declare(strict_types=1);

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
use Psr\Http\Message\ResponseInterface;

class PrepareBodyMiddlewareTest extends TestCase
{
    public static function methodProvider(): array
    {
        $cases = [];
        $methods = ['GET', 'PUT', 'POST'];
        $bodies = ['Test', ''];
        foreach ($methods as $method) {
            foreach ($bodies as $body) {
                $cases[] = [$method, $body];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider methodProvider
     */
    public function testAddsContentLengthWhenMissingAndPossible(string $method, string $body): void
    {
        $h = new MockHandler([
            static function (RequestInterface $request) use ($body): ResponseInterface {
                $length = \strlen($body);
                if ($length > 0) {
                    self::assertSame((string) $length, $request->getHeaderLine('Content-Length'));
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

    public function testPreservesCustomRequestWhenAddingContentLength(): void
    {
        $h = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
                self::assertInstanceOf(PrepareBodyTestRequest::class, $request);
                self::assertSame('7', $request->getHeaderLine('Content-Length'));

                return new Response(200);
            },
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new PrepareBodyTestRequest('POST', 'http://www.google.com', [], 'payload'), []);
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAddsTransferEncodingWhenNoContentLength(): void
    {
        $body = FnStream::decorate(Psr7\Utils::streamFor('foo'), [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $h = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
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

    public function testAddsContentTypeWhenMissingAndPossible(): void
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));
        $h = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
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

    public static function expectProvider(): array
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
     *
     * @param bool|int $value
     * @param string[] $result
     */
    public function testAddsExpect($value, array $result): void
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));

        $h = new MockHandler([
            static function (RequestInterface $request) use ($result): ResponseInterface {
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

    public static function noExpectProtocolProvider(): array
    {
        return [
            ['1.0'],
            ['2'],
            ['2.0'],
            ['3'],
            ['3.0'],
        ];
    }

    /**
     * @dataProvider noExpectProtocolProvider
     */
    public function testDoesNotAddExpectForProtocolsThatDoNotSupportIt(string $protocolVersion): void
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));

        $h = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
                self::assertFalse($request->hasHeader('Expect'));

                return new Response(200);
            },
        ]);

        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $bd, $protocolVersion), [
            'expect' => true,
        ]);
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPreservesCustomRequestWhenAddingExpect(): void
    {
        $h = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
                self::assertInstanceOf(PrepareBodyTestRequest::class, $request);
                self::assertSame('100-Continue', $request->getHeaderLine('Expect'));

                return new Response(200);
            },
        ]);

        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(
            new PrepareBodyTestRequest('POST', 'http://www.google.com', [], 'payload'),
            ['expect' => true]
        );
        self::assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testIgnoresIfExpectIsPresent(): void
    {
        $bd = Psr7\Utils::streamFor(\fopen(__DIR__.'/../composer.json', 'r'));
        $h = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
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

final class PrepareBodyTestRequest extends Request
{
}
