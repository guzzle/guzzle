<?php
namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Client;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Transaction;

class FutureResponseTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Unknown property: foo
     */
    public function testValidatesMagicMethod()
    {
        $f = new FutureResponse(function () {});
        $f->foo;
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Future did not return a valid transaction. Got NULL
     */
    public function testEnsuresDerefReturnsTransaction()
    {
        $f = new FutureResponse(function () {});
        $f->getStatusCode();
    }

    public function testDoesTheSameAsResponseWhenDereferenced()
    {
        $str = Stream::factory('foo');
        $response = new Response(200, ['Foo' => 'bar'], $str);
        $future = new FutureResponse(function () use ($response) {
            $t = new Transaction(
                new Client(),
                new Request('GET', 'http://www.foo.com')
            );
            $t->response = $response;
            $response->setEffectiveUrl('http://www.foo.com');
            return $t;
        });
        $this->assertFalse($future->realized());
        $this->assertEquals(200, $future->getStatusCode());
        $this->assertTrue($future->realized());
        // Deref again does nothing.
        $future->deref();
        $this->assertTrue($future->realized());
        $this->assertEquals('bar', $future->getHeader('Foo'));
        $this->assertEquals(['bar'], $future->getHeaderLines('Foo'));
        $this->assertSame($response->getHeaders(), $future->getHeaders());
        $this->assertSame(
            $response->getBody(),
            $future->getBody()
        );
        $this->assertSame(
            $response->getProtocolVersion(),
            $future->getProtocolVersion()
        );
        $this->assertSame(
            $response->getEffectiveUrl(),
            $future->getEffectiveUrl()
        );
        $future->setEffectiveUrl('foo');
        $this->assertEquals('foo', $response->getEffectiveUrl());
        $this->assertSame(
            $response->getReasonPhrase(),
            $future->getReasonPhrase()
        );

        $this->assertTrue($future->hasHeader('foo'));

        $future->removeHeader('Foo');
        $this->assertFalse($future->hasHeader('foo'));
        $this->assertFalse($response->hasHeader('foo'));

        $future->setBody(Stream::factory('true'));
        $this->assertEquals('true', (string) $response->getBody());
        $this->assertTrue($future->json());
        $this->assertSame((string) $response, (string) $future);

        $future->setBody(Stream::factory('<a><b>c</b></a>'));
        $this->assertEquals('c', (string) $future->xml()->b);

        $future->addHeader('a', 'b');
        $this->assertEquals('b', $future->getHeader('a'));

        $future->addHeaders(['a' => '2']);
        $this->assertEquals('b, 2', $future->getHeader('a'));

        $future->setHeader('a', '2');
        $this->assertEquals('2', $future->getHeader('a'));

        $future->setHeaders(['a' => '3']);
        $this->assertEquals(['a' => ['3']], $future->getHeaders());
    }

    public function testCanDereferenceManually()
    {
        $response = new Response(200, ['Foo' => 'bar']);
        $future = new FutureResponse(function () use ($response) {
            $t = new Transaction(
                new Client(),
                new Request('GET', 'http://www.foo.com')
            );
            $t->response = $response;
            return $t;
        });
        $this->assertSame($response, $future->deref());
        $this->assertTrue($future->realized());
    }

    public function testCanCancel()
    {
        $c = false;
        $future = new FutureResponse(
            function () {},
            function () use (&$c) {
                $c = true;
                return true;
            }
        );
        $this->assertFalse($future->cancelled());
        $this->assertTrue($future->cancel());
        $this->assertTrue($future->cancelled());
        $this->assertFalse($future->cancel());
    }

    public function testCanCancelButReturnsFalseForNoCancelFunction()
    {
        $future = new FutureResponse(function () {});
        $this->assertFalse($future->cancel());
        $this->assertTrue($future->cancelled());
    }

    /**
     * @expectedException \GuzzleHttp\Ring\Exception\CancelledFutureAccessException
     */
    public function testAccessingCancelledResponseThrows()
    {
        $future = new FutureResponse(function () {});
        $this->assertFalse($future->cancel());
        $future->getStatusCode();
    }
}
