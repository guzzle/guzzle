<?php

namespace GuzzleHttp\Tests\Stream;

use GuzzleHttp\Stream\Stream;

/**
 * @covers GuzzleHttp\Stream\Stream
 */
class StreamTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testConstructorThrowsExceptionOnInvalidArgument()
    {
        new Stream(true);
    }

    public function testConstructorInitializesProperties()
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isWritable());
        $this->assertTrue($stream->isSeekable());
        $this->assertEquals('php://temp', $stream->getMetadata('uri'));
        $this->assertInternalType('array', $stream->getMetadata());
        $this->assertEquals(4, $stream->getSize());
        $this->assertFalse($stream->eof());
        $stream->close();
    }

    public function testStreamClosesHandleOnDestruct()
    {
        $handle = fopen('php://temp', 'r');
        $stream = new Stream($handle);
        unset($stream);
        $this->assertFalse(is_resource($handle));
    }

    public function testConvertsToString()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertEquals('data', (string) $stream);
        $this->assertEquals('data', (string) $stream);
        $stream->close();
    }

    public function testGetsContents()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertEquals('', $stream->getContents());
        $stream->seek(0);
        $this->assertEquals('data', $stream->getContents());
        $this->assertEquals('', $stream->getContents());
        $stream->seek(0);
        $this->assertEquals('da', $stream->getContents(2));
        $stream->close();
    }

    public function testChecksEof()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertFalse($stream->eof());
        $stream->read(4);
        $this->assertTrue($stream->eof());
        $stream->close();
    }

    public function testAllowsSettingManualSize()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $stream->setSize(10);
        $this->assertEquals(10, $stream->getSize());
        $stream->close();
    }

    public function testGetSize()
    {
        $size = filesize(__FILE__);
        $handle = fopen(__FILE__, 'r');
        $stream = new Stream($handle);
        $this->assertEquals($size, $stream->getSize());
        // Load from cache
        $this->assertEquals($size, $stream->getSize());
        $stream->close();
    }

    public function testEnsuresSizeIsConsistent()
    {
        $h = fopen('php://temp', 'w+');
        $this->assertEquals(3, fwrite($h, 'foo'));
        $stream = new Stream($h);
        $this->assertEquals(3, $stream->getSize());
        $this->assertEquals(4, $stream->write('test'));
        $this->assertEquals(7, $stream->getSize());
        $this->assertEquals(7, $stream->getSize());
        $stream->close();
    }

    public function testProvidesStreamPosition()
    {
        $handle = fopen('php://temp', 'w+');
        $stream = new Stream($handle);
        $this->assertEquals(0, $stream->tell());
        $stream->write('foo');
        $this->assertEquals(3, $stream->tell());
        $stream->seek(1);
        $this->assertEquals(1, $stream->tell());
        $this->assertSame(ftell($handle), $stream->tell());
        $stream->close();
    }

    public function testKeepsPositionOfResource()
    {
        $h = fopen(__FILE__, 'r');
        fseek($h, 10);
        $stream = Stream::factory($h);
        $this->assertEquals(10, $stream->tell());
        $stream->close();
    }

    public function testCanDetachStream()
    {
        $r = fopen('php://temp', 'w+');
        $stream = new Stream($r);
        $this->assertTrue($stream->isReadable());
        $stream->detach();
        $this->assertFalse($stream->isReadable());
        $stream->close();
    }

    public function testCreatesFromString()
    {
        $stream = Stream::fromString('foo');
        $this->assertInstanceOf('GuzzleHttp\Stream\Stream', $stream);
        $this->assertEquals('foo', $stream->getContents());
        $stream->close();
    }

    public function testFactoryCreatesFromStringAndIgnoresSameType()
    {
        $s = Stream::factory();
        $this->assertInstanceOf('GuzzleHttp\Stream\Stream', $s);
        $this->assertSame($s, Stream::factory($s));
    }

    public function testFactoryCreatesFromResource()
    {
        $r = fopen(__FILE__, 'r');
        $s = Stream::factory($r);
        $this->assertInstanceOf('GuzzleHttp\Stream\Stream', $s);
        $this->assertSame(file_get_contents(__FILE__), (string) $s);
    }

    public function testFactoryCreatesFromObjectWithToString()
    {
        $r = new HasToString();
        $s = Stream::factory($r);
        $this->assertInstanceOf('GuzzleHttp\Stream\Stream', $s);
        $this->assertEquals('foo', (string) $s);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testFactoryThrowsExceptionForUnknown()
    {
        Stream::factory(new \stdClass());
    }

    public function testReadsLines()
    {
        $s = Stream::factory("foo\nbaz\nbar");
        $this->assertEquals("foo\n", Stream::readLine($s));
        $this->assertEquals("baz\n", Stream::readLine($s));
        $this->assertEquals("bar", Stream::readLine($s));
    }

    public function testReadsLinesUpToMaxLength()
    {
        $s = Stream::factory("12345\n");
        $this->assertEquals("123", Stream::readLine($s, 4));
        $this->assertEquals("45\n", Stream::readLine($s));
    }

    public function testReadsLineUntilFalseReturnedFromRead()
    {
        $s = $this->getMockBuilder('GuzzleHttp\Stream\Stream')
            ->setMethods(['read', 'eof'])
            ->disableOriginalConstructor()
            ->getMock();
        $s->expects($this->exactly(2))
            ->method('read')
            ->will($this->returnCallback(function () {
                static $c = false;
                if ($c) {
                    return false;
                }
                $c = true;
                return 'h';
            }));
        $s->expects($this->exactly(2))->method('eof')->will($this->returnValue(false));
        $this->assertEquals("h", Stream::readLine($s));
    }

    public function testCalculatesHash()
    {
        $s = Stream::factory('foobazbar');
        $this->assertEquals(md5('foobazbar'), Stream::getHash($s, 'md5'));
    }

    public function testCalculatesHashSeeksToOriginalPosition()
    {
        $s = Stream::factory('foobazbar');
        $s->seek(4);
        $this->assertEquals(md5('foobazbar'), Stream::getHash($s, 'md5'));
        $this->assertEquals(4, $s->tell());
    }
}

class HasToString
{
    public function __toString() {
        return 'foo';
    }
}
