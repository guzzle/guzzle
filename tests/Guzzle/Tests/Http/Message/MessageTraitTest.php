<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\MessageTrait;
use Guzzle\Http\Message\Request;
use Guzzle\Stream\Stream;

class Message {
    use MessageTrait;
}

/**
 * @covers \Guzzle\Http\Message\MessageTrait
 */
class MessageTraitTest extends \PHPUnit_Framework_TestCase
{
    public function testHasProtocolVersion()
    {
        $m = new Message();
        $this->assertEquals(1.1, $m->getProtocolVersion());
    }

    public function testHasHeaders()
    {
        $m = new Message();
        $this->assertFalse($m->hasHeader('foo'));
        $m->addHeader('foo', 'bar');
        $this->assertTrue($m->hasHeader('foo'));
    }

    public function testInitializesMessageWithProtocolVersionOption()
    {
        $m = new Request('GET', '/', [], null, [
            'protocol_version' => '10'
        ]);
        $this->assertEquals(10, $m->getProtocolVersion());
    }

    public function testHasBody()
    {
        $m = new Message();
        $this->assertNull($m->getBody());
        $s = Stream::factory('test');
        $m->setBody($s);
        $this->assertSame($s, $m->getBody());
        $this->assertEquals('4', $m->getHeader('Content-Length'));
    }

    public function testSetsContentTypeIfPossibleFromStream()
    {
        $s = $this->getMockBuilder('Guzzle\Stream\MetadataStreamInterface')
            ->setMethods(['getMetadata', 'getSize'])
            ->getMockForAbstractClass();
        $s->expects($this->exactly(1))
            ->method('getMetadata')
            ->with('uri')
            ->will($this->returnValue('/foo/baz/bar.jpg'));
        $s->expects($this->exactly(2))
            ->method('getSize')
            ->will($this->returnValue(4));

        $m = new Message();
        $m->setBody($s);
        $this->assertSame($s, $m->getBody());
        $this->assertEquals('4', $m->getHeader('Content-Length'));
        $this->assertEquals('image/jpeg', $m->getHeader('Content-Type'));

        $m = new Message();
        $m->setHeader('Content-Type', 'foo/baz');
        $m->setBody($s);
        $this->assertEquals('foo/baz', $m->getHeader('Content-Type'));
    }

    public function testCanRemoveBodyBySettingToNullAndRemovesCommonBodyHeaders()
    {
        $m = new Message();
        $m->setBody(Stream::factory('foo'));
        $m->setHeader('Content-Length', 3)->setHeader('Transfer-Encoding', 'chunked');
        $m->setBody(null);
        $this->assertNull($m->getBody());
        $this->assertFalse($m->hasHeader('Content-Length'));
        $this->assertFalse($m->hasHeader('Transfer-Encoding'));
    }
}
