<?php

namespace Guzzle\Tests\Http\Message\Post;

use Guzzle\Http\Message\Post\PostFile;
use Guzzle\Stream\Stream;

/**
 * @covers Guzzle\Http\Message\Post\PostFile
 */
class PostFileTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesFromString()
    {
        $p = new PostFile('foo', 'hi', 'test.php');
        $this->assertInstanceOf('Guzzle\Http\Message\Post\PostFileInterface', $p);
        $this->assertEquals('hi', $p->getContent());
        $this->assertEquals('foo', $p->getName());
        $this->assertEquals('test.php', $p->getFilename());
        $this->assertEquals('form-data; filename="test.php"; name="foo"', $p->getHeader('content-disposition'));
    }

    public function testGetsFilenameFromMetadata()
    {
        $p = new PostFile('foo', fopen(__FILE__, 'r'));
        $this->assertEquals(__FILE__, $p->getFilename());
    }

    public function testDefaultsToNameWhenNoFilenameExists()
    {
        $p = new PostFile('foo', 'bar');
        $this->assertEquals('foo', $p->getFilename());
    }

    public function testCreatesFromMultipartFormData()
    {
        $mp = $this->getMockBuilder('Guzzle\Http\Message\Post\MultipartBody')
            ->setMethods(['getBoundary'])
            ->disableOriginalConstructor()
            ->getMock();
        $mp->expects($this->once())
            ->method('getBoundary')
            ->will($this->returnValue('baz'));

        $p = new PostFile('foo', $mp);
        $this->assertEquals('form-data; name="foo"', $p->getHeader('Content-Disposition'));
        $this->assertEquals('multipart/form-data; boundary=baz', $p->getHeader('Content-Type'));
    }

    public function testCanAddHeaders()
    {
        $p = new PostFile('foo', Stream::factory('hi', true), 'test.php', [
            'X-Foo' => '123',
            'Content-Disposition' => 'bar'
        ]);
        $this->assertEquals('bar', $p->getHeader('Content-Disposition'));
        $this->assertEquals('123', $p->getHeader('X-Foo'));
    }
}
