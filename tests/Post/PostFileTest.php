<?php

namespace GuzzleHttp\Tests\Post;

use GuzzleHttp\Post\PostFile;
use GuzzleHttp\Stream\Stream;

/**
 * @covers GuzzleHttp\Post\PostFile
 */
class PostFileTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesFromString()
    {
        $p = new PostFile('foo', 'hi', '/path/to/test.php');
        $this->assertInstanceOf('GuzzleHttp\Post\PostFileInterface', $p);
        $this->assertEquals('hi', $p->getContent());
        $this->assertEquals('foo', $p->getName());
        $this->assertEquals('/path/to/test.php', $p->getFilename());
        $this->assertEquals(
            'form-data; name="foo"; filename="test.php"',
            $p->getHeaders()['Content-Disposition']
        );
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
        $mp = $this->getMockBuilder('GuzzleHttp\Post\MultipartBody')
            ->setMethods(['getBoundary'])
            ->disableOriginalConstructor()
            ->getMock();
        $mp->expects($this->once())
            ->method('getBoundary')
            ->will($this->returnValue('baz'));

        $p = new PostFile('foo', $mp);
        $this->assertEquals(
            'form-data; name="foo"',
            $p->getHeaders()['Content-Disposition']
        );
        $this->assertEquals(
            'multipart/form-data; boundary=baz',
            $p->getHeaders()['Content-Type']
        );
    }

    public function testCanAddHeaders()
    {
        $p = new PostFile('foo', Stream::factory('hi'), 'test.php', [
            'X-Foo' => '123',
            'Content-Disposition' => 'bar'
        ]);
        $this->assertEquals('bar', $p->getHeaders()['Content-Disposition']);
        $this->assertEquals('123', $p->getHeaders()['X-Foo']);
    }
}
