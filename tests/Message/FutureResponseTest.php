<?php
namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use React\Promise\Deferred;
use GuzzleHttp\Tests\Subscriber\MockTest;

class FutureResponseTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Class has no foo property
     */
    public function testValidatesMagicMethod()
    {
        $f = MockTest::createFuture(function () {});
        $f->foo;
    }

    public function testDoesTheSameAsResponseWhenDereferenced()
    {
        $str = Stream::factory('foo');
        $response = new Response(200, ['Foo' => 'bar'], $str);
        $future = MockTest::createFuture(function () use ($response) {
            return $response;
        });
        $this->assertFalse($this->readAttribute($future, 'isRealized'));
        $this->assertEquals(200, $future->getStatusCode());
        $this->assertTrue($this->readAttribute($future, 'isRealized'));
        // Deref again does nothing.
        $future->wait();
        $this->assertTrue($this->readAttribute($future, 'isRealized'));
        $this->assertEquals('bar', $future->getHeader('Foo'));
        $this->assertEquals(['bar'], $future->getHeaderAsarray('Foo'));
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
        $future = MockTest::createFuture(function () use ($response) {
            return $response;
        });
        $this->assertSame($response, $future->wait());
        $this->assertTrue($this->readAttribute($future, 'isRealized'));
    }

    public function testCanCancel()
    {
        $c = false;
        $deferred = new Deferred();
        $future = new FutureResponse(
            $deferred->promise(),
            function () {},
            function () use (&$c) {
                $c = true;
                return true;
            }
        );

        $this->assertFalse($this->readAttribute($future, 'isRealized'));
        $future->cancel();
        $this->assertTrue($this->readAttribute($future, 'isRealized'));
        $future->cancel();
    }

    public function testCanCancelButReturnsFalseForNoCancelFunction()
    {
        $future = MockTest::createFuture(function () {});
        $future->cancel();
        $this->assertTrue($this->readAttribute($future, 'isRealized'));
    }

    /**
     * @expectedException \GuzzleHttp\Ring\Exception\CancelledFutureAccessException
     */
    public function testAccessingCancelledResponseThrows()
    {
        $future = MockTest::createFuture(function () {});
        $future->cancel();
        $future->getStatusCode();
    }

    public function testExceptionInToStringTriggersError()
    {
        $future = MockTest::createFuture(function () {
            throw new \Exception('foo');
        });
        $err = '';
        set_error_handler(function () use (&$err) {
            $err = func_get_args()[1];
        });
        echo $future;
        restore_error_handler();
        $this->assertContains('foo', $err);
    }

    public function testProxiesSetters()
    {
        $str = Stream::factory('foo');
        $response = new Response(200, ['Foo' => 'bar'], $str);
        $future = MockTest::createFuture(function () use ($response) {
            return $response;
        });

        $future->setStatusCode(202);
        $this->assertEquals(202, $future->getStatusCode());
        $this->assertEquals(202, $response->getStatusCode());

        $future->setReasonPhrase('foo');
        $this->assertEquals('foo', $future->getReasonPhrase());
        $this->assertEquals('foo', $response->getReasonPhrase());
    }
}
