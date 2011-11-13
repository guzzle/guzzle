<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Stream;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class StreamTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\Stream::__construct
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsExceptionOnInvalidArgument()
    {
        $stream = new Stream(true);
    }

    /**
     * @covers Guzzle\Common\Stream::__construct
     * @covers Guzzle\Common\Stream::getSize
     * @covers Guzzle\Common\Stream::getUri
     * @covers Guzzle\Common\Stream::isReadable
     * @covers Guzzle\Common\Stream::isWritable
     * @covers Guzzle\Common\Stream::isSeekable
     * @covers Guzzle\Common\Stream::isLocal
     * @covers Guzzle\Common\Stream::isConsumed
     * @covers Guzzle\Common\Stream::getStream
     * @covers Guzzle\Common\Stream::getWrapper
     * @covers Guzzle\Common\Stream::getWrapperData
     * @covers Guzzle\Common\Stream::getFilters
     * @covers Guzzle\Common\Stream::getStreamType
     */
    public function testConstructor()
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertEquals($handle, $stream->getStream());
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isWritable());
        $this->assertTrue($stream->isLocal());
        $this->assertTrue($stream->isSeekable());
        $this->assertEquals('php', $stream->getWrapper());
        $this->assertEquals('temp', $stream->getStreamType());
        $this->assertEquals(4, $stream->getSize());
        $this->assertEquals('php://temp', $stream->getUri());
        $this->assertEquals(array(), $stream->getWrapperData());
        $this->assertEquals(array(
            'wrapped' => array(),
            'unwrapped' => array()
        ), $stream->getFilters());
        $this->assertFalse($stream->isConsumed());
        unset($stream);
    }

    /**
     * @covers Guzzle\Common\Stream::__destruct
     */
    public function testStreamClosesHandleOnDestruct()
    {
        $handle = fopen('php://temp', 'r');
        $stream = new Stream($handle);
        unset($stream);
        $this->assertFalse(is_resource($handle));
    }

    /**
     * @covers Guzzle\Common\Stream::__toString
     */
    public function testConvertsToString()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertEquals('data', (string)$stream);
        unset($stream);

        $handle = fopen(__DIR__ . '/../TestData/FileBody.txt', 'w');
        $stream = new Stream($handle);
        $this->assertEquals('', (string)$stream);
        unset($stream);
    }

    /**
     * @covers Guzzle\Common\Stream::isConsumed
     */
    public function testIsConsumed()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertFalse($stream->isConsumed());
        $stream->read(4);
        $this->assertTrue($stream->isConsumed());
    }

    /**
     * @covers Guzzle\Common\Stream::setSize
     * @covers Guzzle\Common\Stream::getSize
     */
    public function testAllowsSettingManualSize()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $stream->setSize(10);
        $this->assertEquals(10, $stream->getSize());
        unset($stream);
    }

    /**
     * @covers Guzzle\Common\Stream::read
     * @covers Guzzle\Common\Stream::write
     * @covers Guzzle\Common\Stream::seek
     * @covers Guzzle\Common\Stream::isReadable
     * @covers Guzzle\Common\Stream::isSeekable
     */
    public function testWrapsStream()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, 'data');
        $stream = new Stream($handle);
        $this->assertTrue($stream->isSeekable());
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->seek(0));
        $this->assertEquals('da', $stream->read(2));
        $this->assertEquals('ta', $stream->read(2));
        $this->assertTrue($stream->seek(0));
        $this->assertEquals('data', $stream->read(4));
        $stream->write('_appended');
        $stream->seek(0);
        $this->assertEquals('data_appended', $stream->read(13));
    }

    /**
     * @covers Guzzle\Common\Stream::getSize
     * @covers Guzzle\Common\Stream::__construct
     */
    public function testGetSize()
    {
        $size = filesize(__DIR__ . '/../../../bootstrap.php');
        $handle = fopen(__DIR__ . '/../../../bootstrap.php', 'r');
        $stream = new Stream($handle);
        $stream->addFilter('string.rot13', \STREAM_FILTER_READ);
        $this->assertEquals($handle, $stream->getStream());
        $this->assertEquals($size, $stream->getSize());
        $this->assertEquals($size, $stream->getSize());
        $stream->addFilter('zlib.inflate', \STREAM_FILTER_READ);
        $this->assertEquals(false, $stream->getSize());
        unset($stream);

        // Make sure that false is returned when the size cannot be determined
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Lenght: 0\r\n\r\n");
        $handle = fopen('http://localhost:' . $this->getServer()->getPort(), 'r');
        $stream = new Stream($handle);
        $this->assertEquals(false, $stream->getSize());
        unset($stream);
    }

    /**
     * @covers Guzzle\Common\Stream::addFilter
     * @covers Guzzle\Common\Stream::getFilters
     */
    public function testAddFilter()
    {
        $handle = fopen(__DIR__ . '/../../../bootstrap.php', 'r');
        $stream = new Stream($handle);
        $stream->addFilter('string.rot13', \STREAM_FILTER_READ);
        // Prepend this one
        $stream->addFilter('string.toupper', \STREAM_FILTER_READ, null, true);

        $filters = $stream->getFilters();
        $this->assertArrayHasKey('wrapped', $filters);
        $this->assertArrayHasKey('unwrapped', $filters);
        $this->assertEquals(array(), $filters['unwrapped']);
        $this->assertArrayHasKey('string.rot13|1', $filters['wrapped']);
        $this->assertInternalType('resource', $filters['wrapped']['string.rot13|1']);

        unset($stream);
    }

    /**
     * @covers Guzzle\Common\Stream::addFilter
     * @covers Guzzle\Common\Stream::getFilters
     * @covers Guzzle\Common\Stream::removeFilter
     */
    public function testRemoveFilter()
    {
        $handle = fopen(__DIR__ . '/../../../bootstrap.php', 'r');
        $stream = new Stream($handle);
        $stream->addFilter('string.rot13', \STREAM_FILTER_READ, false);
        $data = (string)$stream;

        $filters = $stream->getFilters();
        $this->assertTrue($stream->removeFilter('string.rot13', \STREAM_FILTER_READ));
        $this->assertNotEquals($filters, $stream->getFilters());
        $this->assertNotEquals($data, (string)$stream);

        $this->assertFalse($stream->removeFilter('string.rot13', \STREAM_FILTER_READ));
        unset($stream);
    }
}