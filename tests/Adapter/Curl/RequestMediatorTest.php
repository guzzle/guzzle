<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

use GuzzleHttp\Adapter\Curl\RequestMediator;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\HeadersEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Adapter\Curl\RequestMediator
 */
class RequestMediatorTest extends \PHPUnit_Framework_TestCase
{
    public function testSetsResponseBodyForDownload()
    {
        $body = Stream::factory();
        $request = new Request('GET', '/');
        $ee = null;
        $request->getEmitter()->on(
            'headers',
            function (HeadersEvent $e) use (&$ee) {
                $ee = $e;
            }
        );
        $t = new Transaction(new Client(), $request);
        $m = new RequestMediator($t, new MessageFactory());
        $m->setResponseBody($body);
        $this->assertEquals(18, $m->receiveResponseHeader(null, "HTTP/1.1 202 FOO\r\n"));
        $this->assertEquals(10, $m->receiveResponseHeader(null, "Foo: Bar\r\n"));
        $this->assertEquals(11, $m->receiveResponseHeader(null, "Baz : Bam\r\n"));
        $this->assertEquals(19, $m->receiveResponseHeader(null, "Content-Length: 3\r\n"));
        $this->assertEquals(2, $m->receiveResponseHeader(null, "\r\n"));
        $this->assertNotNull($ee);
        $this->assertEquals(202, $t->getResponse()->getStatusCode());
        $this->assertEquals('FOO', $t->getResponse()->getReasonPhrase());
        $this->assertEquals('Bar', $t->getResponse()->getHeader('Foo'));
        $this->assertEquals('Bam', $t->getResponse()->getHeader('Baz'));
        $m->writeResponseBody(null, 'foo');
        $this->assertEquals('foo', (string) $body);
        $this->assertEquals('3', $t->getResponse()->getHeader('Content-Length'));
    }

    public function testSendsToNewBodyWhenNot2xxResponse()
    {
        $body = Stream::factory();
        $request = new Request('GET', '/');
        $t = new Transaction(new Client(), $request);
        $m = new RequestMediator($t, new MessageFactory());
        $m->setResponseBody($body);
        $this->assertEquals(27, $m->receiveResponseHeader(null, "HTTP/1.1 304 Not Modified\r\n"));
        $this->assertEquals(2, $m->receiveResponseHeader(null, "\r\n"));
        $this->assertEquals(304, $t->getResponse()->getStatusCode());
        $m->writeResponseBody(null, 'foo');
        $this->assertEquals('', (string) $body);
        $this->assertEquals('foo', (string) $t->getResponse()->getBody());
    }

    public function testUsesDefaultBodyIfNoneSet()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->setResponse(new Response(200));
        $m = new RequestMediator($t, new MessageFactory());
        $this->assertEquals(3, $m->writeResponseBody(null, 'foo'));
        $this->assertEquals('foo', (string) $t->getResponse()->getBody());
    }

    public function testCanUseResponseBody()
    {
        $body = Stream::factory();
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->setResponse(new Response(200, [], $body));
        $m = new RequestMediator($t, new MessageFactory());
        $this->assertEquals(3, $m->writeResponseBody(null, 'foo'));
        $this->assertEquals('foo', (string) $body);
    }

    public function testHandlesTransactionWithNoResponseWhenWritingBody()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $m = new RequestMediator($t, new MessageFactory());
        $this->assertEquals(0, $m->writeResponseBody(null, 'test'));
    }

    public function testReadsFromRequestBody()
    {
        $body = Stream::factory('foo');
        $t = new Transaction(new Client(), new Request('PUT', '/', [], $body));
        $m = new RequestMediator($t, new MessageFactory());
        $this->assertEquals('foo', $m->readRequestBody(null, null, 3));
    }
}
