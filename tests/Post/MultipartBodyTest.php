<?php

namespace GuzzleHttp\Tests\Post;

use GuzzleHttp\Post\MultipartBody;
use GuzzleHttp\Post\PostFile;

/**
 * @covers GuzzleHttp\Post\MultipartBody
 */
class MultipartBodyTest extends \PHPUnit_Framework_TestCase
{
    protected function getTestBody()
    {
        return new MultipartBody(['foo' => 'bar'], [
            new PostFile('foo', 'abc', 'foo.txt')
        ], 'abcdef');
    }

    public function testConstructorAddsFieldsAndFiles()
    {
        $b = $this->getTestBody();
        $this->assertEquals('abcdef', $b->getBoundary());
        $c = (string) $b;
        $this->assertContains("--abcdef\r\nContent-Disposition: form-data; name=\"foo\"\r\n\r\nbar\r\n", $c);
        $this->assertContains("--abcdef\r\nContent-Disposition: form-data; filename=\"foo.txt\"; name=\"foo\"\r\n"
            . "Content-Type: text/plain\r\n\r\nabc\r\n--abcdef--", $c);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testConstructorValidatesFiles()
    {
        new MultipartBody([], ['bar']);
    }

    public function testConstructorCanCreateBoundary()
    {
        $b = new MultipartBody();
        $this->assertNotNull($b->getBoundary());
    }

    public function testWrapsStreamMethods()
    {
        $b = $this->getTestBody();
        $this->assertFalse($b->write('foo'));
        $this->assertFalse($b->isWritable());
        $this->assertTrue($b->isReadable());
        $this->assertTrue($b->isSeekable());
        $this->assertEquals(0, $b->tell());
    }

    public function testCanDetachFieldsAndFiles()
    {
        $b = $this->getTestBody();
        $b->detach();
        $b->close();
        $this->assertEquals('', (string) $b);
    }

    public function testCanOnlySeekTo0()
    {
        $b = new MultipartBody();
        $this->assertFalse($b->seek(10));
    }

    public function testIsSeekableReturnsTrueIfAllAreSeekable()
    {
        $s = $this->getMockBuilder('GuzzleHttp\Stream\StreamInterface')
            ->setMethods(['isSeekable'])
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('isSeekable')
            ->will($this->returnValue(false));
        $p = new PostFile('foo', $s, 'foo.php');
        $b = new MultipartBody([], [$p]);
        $this->assertFalse($b->isSeekable());
        $this->assertFalse($b->seek(10));
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testThrowsExceptionWhenStreamFailsToRewind()
    {
        $s = $this->getMockBuilder('GuzzleHttp\Stream\StreamInterface')
            ->setMethods(['seek', 'isSeekable'])
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('isSeekable')
            ->will($this->returnValue(true));
        $s->expects($this->once())
            ->method('seek')
            ->will($this->returnValue(false));
        $b = new MultipartBody([], [new PostFile('foo', $s, 'foo.php')]);
        $b->seek(0);
    }

    public function testGetContentsCanCap()
    {
        $b = $this->getTestBody();
        $c = (string) $b;
        $b->seek(0);
        $this->assertSame(substr($c, 0, 10), $b->getContents(10));
    }

    public function testReadsFromBuffer()
    {
        $b = $this->getTestBody();
        $c = $b->read(1);
        $c .= $b->read(1);
        $c .= $b->read(1);
        $c .= $b->read(1);
        $c .= $b->read(1);
        $this->assertEquals('--abc', $c);
    }

    public function testCalculatesSize()
    {
        $b = $this->getTestBody();
        $this->assertEquals(strlen($b), $b->getSize());
    }

    public function testCalculatesSizeAndReturnsNullForUnknown()
    {
        $s = $this->getMockBuilder('GuzzleHttp\Stream\StreamInterface')
            ->setMethods(['getSize'])
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('getSize')
            ->will($this->returnValue(null));
        $b = new MultipartBody([], [new PostFile('foo', $s, 'foo.php')]);
        $this->assertNull($b->getSize());
    }
}
