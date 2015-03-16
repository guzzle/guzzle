<?php
namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\FulfilledResponse;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;

/**
 * @covers GuzzleHttp\FulfilledResponse
 * @covers GuzzleHttp\Psr7\MessageTrait
 * @covers GuzzleHttp\Psr7\Response
 */
class FulfilledResponseTest extends \PHPUnit_Framework_TestCase
{
    public function testActsAsResponse()
    {
        $r = new Response(200, ['foo' => 'bar'], 'baz', '1.1', 'bam');
        $p = new FulfilledResponse($r);
        $this->assertEquals('fulfilled', $p->getState());
        $this->assertEquals(200, $p->getStatusCode());
        $this->assertEquals('bam', $p->getReasonPhrase());
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
}
