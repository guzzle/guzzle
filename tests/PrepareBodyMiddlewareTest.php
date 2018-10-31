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
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\TestCase;

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
            function (RequestInterface $request) use ($body) {
                $length = strlen($body);
                if ($length > 0) {
                    $this->assertEquals($length, $request->getHeaderLine('Content-Length'));
                } else {
                    $this->assertFalse($request->hasHeader('Content-Length'));
                }
                return new Response(200);
            }
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request($method, 'http://www.google.com', [], $body), []);
        $this->assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAddsTransferEncodingWhenNoContentLength()
    {
        $body = FnStream::decorate(Psr7\stream_for('foo'), [
            'getSize' => function () { return null; }
        ]);
        $h = new MockHandler([
            function (RequestInterface $request) {
                $this->assertFalse($request->hasHeader('Content-Length'));
                $this->assertSame('chunked', $request->getHeaderLine('Transfer-Encoding'));
                return new Response(200);
            }
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $body), []);
        $this->assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAddsContentTypeWhenMissingAndPossible()
    {
        $bd = Psr7\stream_for(fopen(__DIR__ . '/../composer.json', 'r'));
        $h = new MockHandler([
            function (RequestInterface $request) {
                $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
                $this->assertTrue($request->hasHeader('Content-Length'));
                return new Response(200);
            }
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $bd), []);
        $this->assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function expectProvider()
    {
        return [
            [true, ['100-Continue']],
            [false, []],
            [10, ['100-Continue']],
            [500000, []]
        ];
    }

    /**
     * @dataProvider expectProvider
     */
    public function testAddsExpect($value, $result)
    {
        $bd = Psr7\stream_for(fopen(__DIR__ . '/../composer.json', 'r'));

        $h = new MockHandler([
            function (RequestInterface $request) use ($result) {
                $this->assertSame($result, $request->getHeader('Expect'));
                return new Response(200);
            }
        ]);

        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $bd), [
            'expect' => $value
        ]);
        $this->assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIgnoresIfExpectIsPresent()
    {
        $bd = Psr7\stream_for(fopen(__DIR__ . '/../composer.json', 'r'));
        $h = new MockHandler([
            function (RequestInterface $request) {
                $this->assertSame(['Foo'], $request->getHeader('Expect'));
                return new Response(200);
            }
        ]);

        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(
            new Request('PUT', 'http://www.google.com', ['Expect' => 'Foo'], $bd),
            ['expect' => true]
        );
        $this->assertInstanceOf(PromiseInterface::class, $p);
        $response = $p->wait();
        $this->assertSame(200, $response->getStatusCode());
    }
}
