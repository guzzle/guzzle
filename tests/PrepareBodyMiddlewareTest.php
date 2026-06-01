<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\RequestException;
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

    /**
     * @dataProvider requestBodyGetSizeFailureMessageProvider
     */
    public function testRequestBodyGetSizeFailureUsesExpectedMessage(\Exception $previous, string $expected): void
    {
        $body = FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function () use ($previous): ?int {
                throw $previous;
            },
        ]);
        $handler = new MockHandler([
            static function (): ResponseInterface {
                self::fail('The request should fail before reaching the handler.');
            },
        ]);
        $stack = new HandlerStack($handler);
        $stack->push(Middleware::prepareBody());
        $composed = $stack->resolve();
        $request = new Request('POST', 'http://example.com', [], $body);

        try {
            $composed($request, [])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame($expected, $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public static function requestBodyGetSizeFailureMessageProvider(): iterable
    {
        return [
            'timeout' => [
                new Psr7\Exception\TimeoutException('Unable to determine stream size: timed out'),
                'Timed out while determining the request body size',
            ],
            'empty message' => [
                new \RuntimeException(''),
                'Failed to determine the request body size',
            ],
            'custom message' => [
                new \RuntimeException('cannot stat custom stream'),
                'cannot stat custom stream',
            ],
            'custom exception' => [
                new \Exception('custom stream exception'),
                'custom stream exception',
            ],
        ];
    }

    public function testRequestBodyGetSizeErrorPropagates(): void
    {
        $previous = new \Error('custom stream bug');
        $body = FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function () use ($previous): ?int {
                throw $previous;
            },
        ]);
        $handler = new MockHandler([
            static function (): ResponseInterface {
                self::fail('The request should fail before reaching the handler.');
            },
        ]);
        $stack = new HandlerStack($handler);
        $stack->push(Middleware::prepareBody());
        $composed = $stack->resolve();
        $request = new Request('POST', 'http://example.com', [], $body);

        try {
            $composed($request, [])->wait();
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }
}

final class PrepareBodyTestRequest extends Request
{
}
