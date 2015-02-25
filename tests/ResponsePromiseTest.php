<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\ResponsePromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;

/**
 * @covers GuzzleHttp\ResponsePromise
 */
class ResponsePromiseTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \BadMethodCallException
     */
    public function testCannotGetUnknownPropery()
    {
        $p = new ResponsePromise();
        $p->not_there;
    }

    public function testUsingAsResponseWaits()
    {
        $r = new Response(200, ['foo' => 'bar'], 'baz');
        $p = new ResponsePromise(function () use (&$p, $r) { $p->resolve($r); });
        $this->assertEquals('pending', $p->getState());
        $this->assertEquals(200, $p->getStatusCode());
        $this->assertEquals('fulfilled', $p->getState());
        $this->assertEquals('OK', $p->getReasonPhrase());
        $this->assertEquals(['foo' => ['bar']], $p->getHeaders());
        $this->assertTrue($p->hasHeader('foo'));
        $this->assertEquals('bar', $p->getHeader('foo'));
        $this->assertEquals(['bar'], $p->getHeaderLines('foo'));
        $this->assertEquals('baz', (string) $p->getBody());
        $this->assertEquals('1.1', $p->getProtocolVersion());
        $this->assertFalse($p->withoutHeader('foo')->hasHeader('foo'));
        $this->assertTrue($p->withHeader('a', 'b')->hasHeader('a'));
        $this->assertTrue($p->withAddedHeader('a', 'b')->hasHeader('a'));
        $this->assertEquals('hi', (string) $p->withBody(Stream::factory('hi'))->getBody());
        $this->assertEquals('201', $p->withStatus('201')->getStatusCode());
        $this->assertEquals('2', $p->withProtocolVersion('2')->getProtocolVersion());
        $this->assertEquals('test', $p->withStatus(201,  'test')->getReasonPhrase());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage A response promise must be resolved with a
     */
    public function testMustResponseWithResponseOrRejectedPromise()
    {
        $p = new ResponsePromise();
        $p->resolve('whoops');
    }

    public function testCreatesFromFulfilledPromise()
    {
        $r = new Response();
        $p = new Promise();
        $p->resolve($r);
        $p2 = ResponsePromise::fromPromise($p);
        $this->assertInstanceOf('GuzzleHttp\FulfilledResponse', $p2);
        $this->assertEquals(200, $p2->getStatusCode());
    }

    public function testCreatesFromPendingPromise()
    {
        $r = new Response();
        $p = new Promise();
        $p2 = ResponsePromise::fromPromise($p);
        $this->assertInstanceOf('GuzzleHttp\ResponsePromise', $p2);
        $p->resolve($r);
        $this->assertEquals(200, $p2->getStatusCode());
    }

    public function testCreatesFromPendingPromiseAndForwardsWait()
    {
        $r = new Response();
        $p = new Promise(function () use (&$p, $r) { $p->resolve($r); });
        $p2 = ResponsePromise::fromPromise($p);
        $this->assertInstanceOf('GuzzleHttp\ResponsePromise', $p2);
        $this->assertEquals(200, $p2->getStatusCode());
    }

    public function testCreatesByExtractingValueFromRejectedPromise()
    {
        $p = new Promise();
        $e = new \Exception('foo');
        $p->reject($e);
        $p2 = ResponsePromise::fromPromise($p);
        $this->assertInstanceOf('GuzzleHttp\RejectedResponse', $p2);
        try {
            $p2->getStatusCode();
            $this->fail();
        } catch (\Exception $e2) {
            $this->assertSame($e2, $e);
        }
    }

    /**
     * @expectedException \UnexpectedValueException
     */
    public function testFailsWhenInvalidState()
    {
        $p = $this->getMockBuilder('GuzzleHttp\Promise\Promise')
            ->setMethods(['getState'])
            ->getMock();
        $p->expects($this->any())
            ->method('getState')
            ->will($this->returnValue('foo'));
        ResponsePromise::fromPromise($p);
    }

    /**
     * @expectedException \UnexpectedValueException
     */
    public function testFailsWhenWaitingOnRejectedDoesNotThrow()
    {
        $p = $this->getMockBuilder('GuzzleHttp\Promise\Promise')
            ->setMethods(['wait', 'getState'])
            ->getMock();
        $p->expects($this->any())
            ->method('getState')
            ->will($this->returnValue('rejected'));
        $p->expects($this->any())
            ->method('wait');
        ResponsePromise::fromPromise($p);
    }
}
