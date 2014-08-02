<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

require_once __DIR__ . '/AbstractCurl.php';

use GuzzleHttp\Adapter\Curl\MultiAdapter;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\NoSeekStream;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Tests\Server;

/**
 * @covers GuzzleHttp\Adapter\Curl\MultiAdapter
 */
class MultiAdapterTest extends AbstractCurl
{
    protected function getAdapter($factory = null, $options = [])
    {
        return new MultiAdapter($factory ?: new MessageFactory(), $options);
    }

    public function testSendsSingleRequest()
    {
        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: bar\r\nContent-Length: 0\r\n\r\n");
        $t = new Transaction(new Client(), new Request('GET', Server::$url));
        $a = new MultiAdapter(new MessageFactory());
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
    }

    public function testCanSetSelectTimeout()
    {
        $current = isset($_SERVER[MultiAdapter::ENV_SELECT_TIMEOUT])
            ? $_SERVER[MultiAdapter::ENV_SELECT_TIMEOUT]: null;
        unset($_SERVER[MultiAdapter::ENV_SELECT_TIMEOUT]);
        $a = new MultiAdapter(new MessageFactory());
        $this->assertEquals(1, $this->readAttribute($a, 'selectTimeout'));
        $a = new MultiAdapter(new MessageFactory(), ['select_timeout' => 10]);
        $this->assertEquals(10, $this->readAttribute($a, 'selectTimeout'));
        $_SERVER[MultiAdapter::ENV_SELECT_TIMEOUT] = 2;
        $a = new MultiAdapter(new MessageFactory());
        $this->assertEquals(2, $this->readAttribute($a, 'selectTimeout'));
        $_SERVER[MultiAdapter::ENV_SELECT_TIMEOUT] = $current;
    }

    /**
     * @expectedException \GuzzleHttp\Exception\AdapterException
     * @expectedExceptionMessage cURL error -2:
     */
    public function testChecksCurlMultiResult()
    {
        MultiAdapter::throwMultiError(-2);
    }

    public function testChecksForCurlException()
    {
        $request = new Request('GET', 'http://httbin.org');
        $transaction = $this->getMockBuilder('GuzzleHttp\Adapter\Transaction')
            ->setMethods(['getRequest'])
            ->disableOriginalConstructor()
            ->getMock();
        $transaction->expects($this->exactly(2))
            ->method('getRequest')
            ->will($this->returnValue($request));
        $context = $this->getMockBuilder('GuzzleHttp\Adapter\Curl\BatchContext')
            ->setMethods(['throwsExceptions'])
            ->disableOriginalConstructor()
            ->getMock();
        $context->expects($this->once())
            ->method('throwsExceptions')
            ->will($this->returnValue(true));
        $a = new MultiAdapter(new MessageFactory());
        $r = new \ReflectionMethod($a, 'isCurlException');
        $r->setAccessible(true);
        try {
            $r->invoke($a, $transaction, ['result' => -10], $context, []);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
            $this->assertContains('[curl] (#-10) ', $e->getMessage());
            $this->assertContains($request->getUrl(), $e->getMessage());
        }
    }

    public function testSendsParallelRequestsFromQueue()
    {
        $c = new Client();
        Server::flush();
        Server::enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ]);
        $transactions = [
            new Transaction($c, new Request('GET', Server::$url)),
            new Transaction($c, new Request('PUT', Server::$url)),
            new Transaction($c, new Request('HEAD', Server::$url)),
            new Transaction($c, new Request('GET', Server::$url))
        ];
        $a = new MultiAdapter(new MessageFactory());
        $a->sendAll(new \ArrayIterator($transactions), 2);
        foreach ($transactions as $t) {
            $response = $t->getResponse();
            $this->assertNotNull($response);
            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testCreatesAndReleasesHandlesWhenNeeded()
    {
        $a = new MultiAdapter(new MessageFactory());
        $c = new Client([
            'adapter'  => $a,
            'base_url' => Server::$url
        ]);

        Server::flush();
        Server::enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]);

        $ef = function (ErrorEvent $e) { throw $e->getException(); };

        $request1 = $c->createRequest('GET', '/');
        $request1->getEmitter()->on('headers', function () use ($a, $c, $ef) {
            $a->send(new Transaction($c, $c->createRequest('GET', '/', [
                'events' => [
                    'headers' => function () use ($a, $c, $ef) {
                        $r = $c->createRequest('GET', '/', [
                            'events' => ['error' => ['fn' => $ef, 'priority' => 9999]]
                        ]);
                        $r->getEmitter()->once('headers', function () use ($a, $c, $r) {
                            $a->send(new Transaction($c, $r));
                        });
                        $a->send(new Transaction($c, $r));
                        // Now, reuse an existing handle
                        $a->send(new Transaction($c, $r));
                        },
                    'error' => ['fn' => $ef, 'priority' => 9999]
                ]
            ])));
        });

        $request1->getEmitter()->on('error', $ef);

        $transactions = [
            new Transaction($c, $request1),
            new Transaction($c, $c->createRequest('PUT')),
            new Transaction($c, $c->createRequest('HEAD'))
        ];

        $a->sendAll(new \ArrayIterator($transactions), 2);

        foreach ($transactions as $index => $t) {
            $response = $t->getResponse();
            $this->assertInstanceOf(
                'GuzzleHttp\\Message\\ResponseInterface',
                $response,
                'Transaction at index ' . $index . ' did not populate response'
            );
            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testThrowsAndReleasesWhenErrorDuringCompleteEvent()
    {
        Server::flush();
        Server::enqueue("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n");
        $request = new Request('GET', Server::$url);
        $request->getEmitter()->on('complete', function (CompleteEvent $e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $t = new Transaction(new Client(), $request);
        $a = new MultiAdapter(new MessageFactory());
        try {
            $a->send($t);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
        }
    }

    public function testEnsuresResponseWasSetForGet()
    {
        $client = new Client();
        $request = $client->createRequest('GET', Server::$url);
        $response = new Response(200, []);
        $er = null;

        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use (&$er, $response) {
                $er = $e;
            }
        );

        $transaction = $this->getMockBuilder('GuzzleHttp\Adapter\Transaction')
            ->setMethods(['getResponse', 'setResponse'])
            ->setConstructorArgs([$client, $request])
            ->getMock();
        $transaction->expects($this->any())->method('setResponse');
        $transaction->expects($this->any())
            ->method('getResponse')
            ->will($this->returnCallback(function () use ($response) {
                $caller = debug_backtrace()[6]['function'];
                return $caller == 'addHandle' ||
                    $caller == 'validateResponseWasSet'
                    ? null
                    : $response;
            }));

        $a = new MultiAdapter(new MessageFactory());
        Server::flush();
        Server::enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
        $a->sendAll(new \ArrayIterator([$transaction]), 10);
        $this->assertNotNull($er);

        $this->assertContains(
            'No response was received',
            $er->getException()->getMessage()
        );
    }

    private function runConnectionTest(
        $queue,
        $stream,
        $msg,
        $statusCode = null
    ) {
        $obj = new \stdClass();
        $er = null;
        $client = new Client();
        $request = $client->createRequest('PUT', Server::$url, [
            'body' => $stream
        ]);

        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use (&$er) {
                $er = $e;
            }
        );

        $transaction = $this->getMockBuilder('GuzzleHttp\Adapter\Transaction')
            ->setMethods(['getResponse', 'setResponse'])
            ->setConstructorArgs([$client, $request])
            ->getMock();

        $transaction->expects($this->any())
            ->method('setResponse')
            ->will($this->returnCallback(function ($r) use (&$obj) {
                $obj->res = $r;
            }));

        $transaction->expects($this->any())
            ->method('getResponse')
            ->will($this->returnCallback(function () use ($obj, &$called) {
                $caller = debug_backtrace()[6]['function'];
                if ($caller == 'addHandle') {
                    return null;
                } elseif ($caller == 'validateResponseWasSet') {
                    return ++$called == 2 ? $obj->res : null;
                } else {
                    return $obj->res;
                }
            }));

        $a = new MultiAdapter(new MessageFactory());
        Server::flush();
        Server::enqueue($queue);
        $a->sendAll(new \ArrayIterator([$transaction]), 10);

        if ($msg) {
            $this->assertNotNull($er);
            $this->assertContains($msg, $er->getException()->getMessage());
        } else {
            $this->assertEquals(
                $statusCode,
                $transaction->getResponse()->getStatusCode()
            );
        }
    }

    public function testThrowsWhenTheBodyCannotBeRewound()
    {
        $this->runConnectionTest(
            ["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"],
            new NoSeekStream(Stream::factory('foo')),
            'attempting to rewind the request body failed'
        );
    }

    public function testRetriesRewindableStreamsWhenClosedConnectionErrors()
    {
        $this->runConnectionTest(
            [
                "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
                "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n",
            ],
            Stream::factory('foo'),
            false,
            201
        );
    }
}
