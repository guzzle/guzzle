<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;

class PoolTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesIterable()
    {
        $p = new Pool(new Client(), 'foo');
        $p->promise()->wait();
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEachElement()
    {
        $c = new Client();
        $requests = ['foo'];
        $p = new Pool($c, new \ArrayIterator($requests));
        $p->promise()->wait();
    }

    public function testSendsAndRealizesFuture()
    {
        $c = $this->getClient();
        $p = new Pool($c, [new Request('GET', 'http://example.com')]);
        $p->promise()->wait();
    }

    public function testExecutesPendingWhenWaiting()
    {
        $r1 = new Promise(function () use (&$r1) { $r1->resolve(new Response()); });
        $r2 = new Promise(function () use (&$r2) { $r2->resolve(new Response()); });
        $r3 = new Promise(function () use (&$r3) { $r3->resolve(new Response()); });
        $handler = new MockHandler([$r1, $r2, $r3]);
        $c = new Client(['handler' => $handler]);
        $p = new Pool($c, [
            new Request('GET', 'http://example.com'),
            new Request('GET', 'http://example.com'),
            new Request('GET', 'http://example.com'),
        ], ['pool_size' => 2]);
        $p->promise()->wait();
    }

    public function testUsesRequestOptions()
    {
        $h = [];
        $handler = new MockHandler([
            function (RequestInterface $request) use (&$h) {
                $h[] = $request;
                return new Response();
            }
        ]);
        $c = new Client(['handler' => $handler]);
        $opts = ['options' => ['headers' => ['x-foo' => 'bar']]];
        $p = new Pool($c, [new Request('GET', 'http://example.com')], $opts);
        $p->promise()->wait();
        $this->assertCount(1, $h);
        $this->assertTrue($h[0]->hasHeader('x-foo'));
    }

    public function testCanProvideCallablesThatReturnResponses()
    {
        $h = [];
        $handler = new MockHandler([
            function (RequestInterface $request) use (&$h) {
                $h[] = $request;
                return new Response();
            }
        ]);
        $c = new Client(['handler' => $handler]);
        $optHistory = [];
        $fn = function (array $opts) use (&$optHistory, $c) {
            $optHistory = $opts;
            return $c->request('GET', 'http://example.com', $opts);
        };
        $opts = ['options' => ['headers' => ['x-foo' => 'bar']]];
        $p = new Pool($c, [$fn], $opts);
        $p->promise()->wait();
        $this->assertCount(1, $h);
        $this->assertTrue($h[0]->hasHeader('x-foo'));
    }

    public function testBatchesResults()
    {
        $requests = [
            new Request('GET', 'http://foo.com/200'),
            new Request('GET', 'http://foo.com/201'),
            new Request('GET', 'http://foo.com/202'),
            new Request('GET', 'http://foo.com/404'),
        ];
        $fn = function (RequestInterface $request) {
            return new Response(substr($request->getUri()->getPath(), 1));
        };
        $mock = new MockHandler([$fn, $fn, $fn, $fn]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $results = Pool::batch($client, $requests);
        $this->assertCount(4, $results);
        $this->assertEquals([0, 1, 2, 3], array_keys($results));
        $this->assertEquals(200, $results[0]->getStatusCode());
        $this->assertEquals(201, $results[1]->getStatusCode());
        $this->assertEquals(202, $results[2]->getStatusCode());
        $this->assertInstanceOf(ClientException::class, $results[3]);
    }

    public function testBatchesResultsWithCallbacks()
    {
        $requests = [
            new Request('GET', 'http://foo.com/200'),
            new Request('GET', 'http://foo.com/201')
        ];
        $mock = new MockHandler([
            function (RequestInterface $request) {
                return new Response(substr($request->getUri()->getPath(), 1));
            }
        ]);
        $client = new Client(['handler' => $mock]);
        $results = Pool::batch($client, $requests, [
            'fulfilled' => function ($value) use (&$called) { $called = true; }
        ]);
        $this->assertCount(2, $results);
        $this->assertTrue($called);
    }

    public function testUsesYieldedKeyInFulfilledCallback()
    {
        $r1 = new Promise(function () use (&$r1) { $r1->resolve(new Response()); });
        $r2 = new Promise(function () use (&$r2) { $r2->resolve(new Response()); });
        $r3 = new Promise(function () use (&$r3) { $r3->resolve(new Response()); });
        $handler = new MockHandler([$r1, $r2, $r3]);
        $c = new Client(['handler' => $handler]);
        $keys = [];
        $requests = [
            'request_1' => new Request('GET', 'http://example.com'),
            'request_2' => new Request('GET', 'http://example.com'),
            'request_3' => new Request('GET', 'http://example.com'),
        ];
        $p = new Pool($c, $requests, [
            'pool_size' => 2,
            'fulfilled' => function($res, $index) use (&$keys) { $keys[] = $index; }
        ]);
        $p->promise()->wait();
        $this->assertCount(3, $keys);
        $this->assertSame($keys, array_keys($requests));
    }

    private function getClient($total = 1)
    {
        $queue = [];
        for ($i = 0; $i < $total; $i++) {
            $queue[] = new Response();
        }
        $handler = new MockHandler($queue);
        return new Client(['handler' => $handler]);
    }
}
