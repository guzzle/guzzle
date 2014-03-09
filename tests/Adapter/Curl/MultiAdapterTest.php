<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

require_once __DIR__ . '/AbstractCurl.php';

use GuzzleHttp\Adapter\Curl\MultiAdapter;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;

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
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 200 OK\r\nFoo: bar\r\nContent-Length: 0\r\n\r\n");
        $t = new Transaction(new Client(), new Request('GET', self::$server->getUrl()));
        $a = new MultiAdapter(new MessageFactory());
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
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

    public function testCreatesAndReleasesHandlesWhenNeeded()
    {
        $c = new Client();
        self::$server->flush();
        self::$server->enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 202 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 203 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 204 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 205 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 206 OK\r\nContent-Length: 0\r\n\r\n",
        ]);

        $a = new MultiAdapter(new MessageFactory());

        $request1 = new Request('GET', self::$server->getUrl());
        $request1->getEmitter()->on('headers', function () use ($a, $c) {
            $request2 = new Request('GET', self::$server->getUrl());
            $request2->getEmitter()->on('headers', function () use ($a, $c) {
                $request3 = new Request('GET', self::$server->getUrl());
                $request3->getEmitter()->on('headers', function () use ($a, $c) {
                    $a->send(new Transaction($c, new Request('GET', self::$server->getUrl())));
                });
                $a->send(new Transaction($c, $request3));
                // Now, reuse an existing handle
                $a->send(new Transaction($c, new Request('GET', self::$server->getUrl())));
            });
            $a->send(new Transaction($c, $request2));
        });

        $transactions = [
            new Transaction($c, $request1),
            new Transaction($c, new Request('PUT', self::$server->getUrl())),
            new Transaction($c, new Request('HEAD', self::$server->getUrl()))
        ];
        $a->sendAll(new \ArrayIterator($transactions), 2);
        $check = range(200, 206);
        foreach ($transactions as $t) {
            $response = $t->getResponse();
            $this->assertInstanceOf(
                'GuzzleHttp\\Message\\ResponseInterface',
                $response
            );
            $this->assertContains($response->getStatusCode(), $check);
        }
    }

    public function testThrowsAndReleasesWhenErrorDuringCompleteEvent()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n");
        $request = new Request('GET', self::$server->getUrl());
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
        //$this->assertEquals(200, $response->getStatusCode());
        //$this->assertEquals('bar', $response->getHeader('Foo'));
    }
}
