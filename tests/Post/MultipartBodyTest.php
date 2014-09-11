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
        $this->assertContains("--abcdef\r\nContent-Disposition: form-data; name=\"foo\"; filename=\"foo.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nabc\r\n--abcdef--", $c);
    }

    public function testDoesNotModifyFieldFormat()
    {
        $m = new MultipartBody(['foo+baz' => 'bar+bam %20 boo'], [
            new PostFile('foo+bar', 'abc %20 123', 'foo.txt')
        ], 'abcdef');
        $this->assertContains('name="foo+baz"', (string) $m);
        $this->assertContains('name="foo+bar"', (string) $m);
        $this->assertContains('bar+bam %20 boo', (string) $m);
        $this->assertContains('abc %20 123', (string) $m);
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

    public function testIsSeekableReturnsTrueIfAllAreSeekable()
    {
        $s = $this->getMockBuilder('GuzzleHttp\Stream\StreamInterface')
            ->setMethods(['isSeekable', 'isReadable'])
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('isSeekable')
            ->will($this->returnValue(false));
        $s->expects($this->once())
            ->method('isReadable')
            ->will($this->returnValue(true));
        $p = new PostFile('foo', $s, 'foo.php');
        $b = new MultipartBody([], [$p]);
        $this->assertFalse($b->isSeekable());
        $this->assertFalse($b->seek(10));
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
            ->setMethods(['getSize', 'isReadable'])
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('getSize')
            ->will($this->returnValue(null));
        $s->expects($this->once())
            ->method('isReadable')
            ->will($this->returnValue(true));
        $b = new MultipartBody([], [new PostFile('foo', $s, 'foo.php')]);
        $this->assertNull($b->getSize());
    }
}
