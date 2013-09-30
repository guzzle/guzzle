<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Adapter\MockAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Message\FutureResponse;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Stream\Stream;

/**
 * @covers \Guzzle\Http\Message\FutureResponse
 */
class FutureResponseTest extends \PHPUnit_Framework_TestCase
{
    public function testHasAdapterAndTransaction()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $a = new MockAdapter();
        $r = new FutureResponse($t, $a);
        $this->assertSame($t, $r->getTransaction());
        $this->assertSame($a, $r->getAdapter());
    }

    public function testProxiesToGeneratedResponse()
    {
        $mr = new Response(200, [
            'X-Foo'          => 'Bar',
            'Content-Length' => '3'
        ], Stream::factory('foo'));

        $a = new MockAdapter();
        $a->setResponse($mr);
        $c = new Client(['adapter' => $a]);
        $t = new Transaction($c, new Request('GET', '/'));
        $r = new FutureResponse($t, $a);

        $this->assertEquals((string) $mr, (string) $r);
        $this->assertEquals(200, $r->send()->getStatusCode());
        $this->assertEquals('OK', $r->send()->getReasonPhrase());
        $this->assertEquals(1.1, $r->getProtocolVersion());

        $this->assertEquals('Bar', $r->getHeader('X-Foo'));
        $this->assertTrue($r->hasHeader('X-Foo'));
        $this->assertEquals([
            'X-Foo' => 'Bar',
            'Content-Length' => '3'
        ], $r->getHeaders());

        $r->addHeader('Test', '123');
        $this->assertTrue($r->hasHeader('Test'));
        $this->assertTrue($mr->hasHeader('Test'));
        $r->removeHeader('Test');
        $this->assertFalse($r->hasHeader('Test'));
        $this->assertFalse($mr->hasHeader('Test'));

        $r->setHeader('Testing', '123');
        $this->assertTrue($r->hasHeader('Testing'));
        $this->assertTrue($mr->hasHeader('Testing'));

        $r->setHeaders(['Abc' => '123']);
        $this->assertEquals(['Abc' => '123'], $r->getHeaders());
        $this->assertEquals(['Abc' => '123'], $mr->getHeaders());

        $r->setEffectiveUrl('test');
        $this->assertEquals('test', $r->getEffectiveUrl());
        $this->assertEquals('test', $mr->getEffectiveUrl());

        $s = Stream::factory('test');
        $r->setBody($s);
        $this->assertSame($r->getBody(), $s);
        $this->assertSame($mr->getBody(), $s);
    }

    public function testProxiesToXmlAndJson()
    {
        $mr = $this->getMockBuilder('Guzzle\Http\Message\Response')
            ->setMethods(['json', 'xml'])
            ->setConstructorArgs([200])
            ->getMock();

        $a = new MockAdapter();
        $a->setResponse($mr);
        $c = new Client(['adapter' => $a]);
        $t = new Transaction($c, new Request('GET', '/'));
        $r = new FutureResponse($t, $a);

        $mr->expects($this->once())
            ->method('json')
            ->will($this->returnValue([]));
        $mr->expects($this->once())
            ->method('xml')
            ->will($this->returnValue(new \SimpleXMLElement('<xml/>')));

        $this->assertEquals([], $r->json());
        $r->xml();
    }
}
