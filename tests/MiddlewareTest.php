<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

class MiddlewareTest extends \PHPUnit_Framework_TestCase
{
    public function testAddsCookiesToRequests()
    {
        $jar = new CookieJar();
        $m = Middleware::cookies($jar);
        $h = new MockHandler(
            [
                function (RequestInterface $request) {
                    return new Response(200, [
                        'Set-Cookie' => new SetCookie([
                            'Name'   => 'name',
                            'Value'  => 'value',
                            'Domain' => 'foo.com'
                        ])
                    ]);
                }
            ]
        );
        $f = $m($h);
        $f(new Request('GET', 'http://foo.com'), []);
        $this->assertCount(1, $jar);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ClientException
     */
    public function testThrowsExceptionOnHttpClientError()
    {
        $m = Middleware::httpError();
        $h = new MockHandler([new Response(404)]);
        $f = $m($h);
        $p = $f(new Request('GET', 'http://foo.com'), []);
        $this->assertEquals('rejected', $p->getState());
        $p->wait();
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ServerException
     */
    public function testThrowsExceptionOnHttpServerError()
    {
        $m = Middleware::httpError();
        $h = new MockHandler([new Response(500)]);
        $f = $m($h);
        $p = $f(new Request('GET', 'http://foo.com'), []);
        $this->assertEquals('rejected', $p->getState());
        $p->wait();
    }

    public function testTracksHistory()
    {
        $container = [];
        $m = Middleware::history($container);
        $h = new MockHandler([new Response(200), new Response(201)]);
        $f = $m($h);
        $p1 = $f(new Request('GET', 'http://foo.com'), ['headers' => ['foo' => 'bar']]);
        $p2 = $f(new Request('HEAD', 'http://foo.com'), ['headers' => ['foo' => 'baz']]);
        $p1->wait();
        $p2->wait();
        $this->assertCount(2, $container);
        $this->assertEquals(200, $container[0]['response']->getStatusCode());
        $this->assertEquals(201, $container[1]['response']->getStatusCode());
        $this->assertEquals('GET', $container[0]['request']->getMethod());
        $this->assertEquals('HEAD', $container[1]['request']->getMethod());
        $this->assertEquals('bar', $container[0]['options']['headers']['foo']);
        $this->assertEquals('baz', $container[1]['options']['headers']['foo']);
    }

    public function testTracksHistoryForFailures()
    {
        $container = [];
        $m = Middleware::history($container);
        $request = new Request('GET', 'http://foo.com');
        $h = new MockHandler([new RequestException('error', $request)]);
        $f = $m($h);
        $f($request, []);
        $this->assertCount(1, $container);
        $this->assertEquals('GET', $container[0]['request']->getMethod());
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $container[0]['error']);
    }

    public function testTapsBeforeAndAfter()
    {
        $calls = [];
        $m = function ($handler) use (&$calls) {
            return function ($request, $options) use ($handler, &$calls) {
                $calls[] = '2';
                return $handler($request, $options);
            };
        };

        $m2 = Middleware::tap(
            function (RequestInterface $request, array $options) use (&$calls) {
                $calls[] = '1';
            },
            function (RequestInterface $request, array $options, PromiseInterface $p) use (&$calls) {
                $calls[] = '3';
            }
        );

        $h = new MockHandler([new Response()]);
        $b = new HandlerStack($h);
        $b->push($m2);
        $b->push($m);
        $comp = $b->resolve();
        $p = $comp(new Request('GET', 'http://foo.com'), []);
        $this->assertEquals('123', implode('', $calls));
        $this->assertInstanceOf('GuzzleHttp\Promise\PromiseInterface', $p);
        $this->assertEquals(200, $p->wait()->getStatusCode());
    }

    public function testBackoffCalculateDelay()
    {
        $this->assertEquals(0, Middleware::exponentialBackoffDelay(0));
        $this->assertEquals(1, Middleware::exponentialBackoffDelay(1));
        $this->assertEquals(2, Middleware::exponentialBackoffDelay(2));
        $this->assertEquals(4, Middleware::exponentialBackoffDelay(3));
        $this->assertEquals(8, Middleware::exponentialBackoffDelay(4));
    }

    public function testRetriesWhenDeciderReturnsTrue()
    {
        $delayCalls = 0;
        $calls = [];
        $decider = function ($retries, $request, $response, $error) use (&$calls) {
            $calls[] = func_get_args();
            return count($calls) < 3;
        };
        $delay = function ($retries) use (&$delayCalls) {
            $delayCalls++;
            $this->assertEquals($retries, $delayCalls);
            return 1;
        };
        $m = Middleware::retry($decider, $delay);
        $h = new MockHandler([new Response(200), new Response(201), new Response(202)]);
        $f = $m($h);
        $c = new Client(['handler' => $f]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
        $this->assertCount(3, $calls);
        $this->assertEquals(2, $delayCalls);
        $this->assertEquals(202, $p->wait()->getStatusCode());
    }

    public function testDoesNotRetryWhenDeciderReturnsFalse()
    {
        $decider = function () { return false; };
        $m = Middleware::retry($decider);
        $h = new MockHandler([new Response(200)]);
        $c = new Client(['handler' => $m($h)]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
        $this->assertEquals(200, $p->wait()->getStatusCode());
    }

    public function testCanRetryExceptions()
    {
        $calls = [];
        $decider = function ($retries, $request, $response, $error) use (&$calls) {
            $calls[] = func_get_args();
            return $error instanceof \Exception;
        };
        $m = Middleware::retry($decider);
        $h = new MockHandler([new \Exception(), new Response(201)]);
        $c = new Client(['handler' => $m($h)]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
        $this->assertEquals(201, $p->wait()->getStatusCode());
        $this->assertCount(2, $calls);
        $this->assertEquals(0, $calls[0][0]);
        $this->assertNull($calls[0][2]);
        $this->assertInstanceOf('Exception', $calls[0][3]);
        $this->assertEquals(1, $calls[1][0]);
        $this->assertInstanceOf('GuzzleHttp\Psr7\Response', $calls[1][2]);
        $this->assertNull($calls[1][3]);
    }

    public function testAddsContentLengthWhenMissingAndPossible()
    {
        $h = new MockHandler([
            function (RequestInterface $request) {
                $this->assertEquals(3, $request->getHeader('Content-Length'));
                return new Response(200);
            }
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], '123'), []);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
        $response = $p->wait();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAddsTransferEncodingWhenNoContentLength()
    {
        $body = FnStream::decorate(Psr7\stream_for('foo'), [
            'getSize' => function () { return null; }
        ]);
        $h = new MockHandler([
            function (RequestInterface $request) {
                $this->assertFalse($request->hasHeader('Content-Length'));
                $this->assertEquals('chunked', $request->getHeader('Transfer-Encoding'));
                return new Response(200);
            }
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $body), []);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
        $response = $p->wait();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAddsContentTypeWhenMissingAndPossible()
    {
        $bd = Psr7\stream_for(fopen(__DIR__ . '/../composer.json', 'r'));
        $h = new MockHandler([
            function (RequestInterface $request) {
                $this->assertEquals('application/json', $request->getHeader('Content-Type'));
                $this->assertTrue($request->hasHeader('Content-Length'));
                return new Response(200);
            }
        ]);
        $m = Middleware::prepareBody();
        $stack = new HandlerStack($h);
        $stack->push($m);
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com', [], $bd), []);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
        $response = $p->wait();
        $this->assertEquals(200, $response->getStatusCode());
    }
}
