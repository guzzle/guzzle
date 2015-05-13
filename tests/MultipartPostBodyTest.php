<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\MultipartPostBody;
use GuzzleHttp\Psr7;

class MultipartPostBodyTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesDefaultBoundary()
    {
        $b = new MultipartPostBody();
        $this->assertNotEmpty($b->getBoundary());
    }

    public function testCanProvideBoundary()
    {
        $b = new MultipartPostBody([], 'foo');
        $this->assertEquals('foo', $b->getBoundary());
    }

    public function testIsNotWritable()
    {
        $b = new MultipartPostBody();
        $this->assertFalse($b->isWritable());
    }

    public function testCanCreateEmptyStream()
    {
        $b = new MultipartPostBody();
        $boundary = $b->getBoundary();
        $this->assertSame("--{$boundary}--\r\n", $b->getContents());
        $this->assertSame(strlen($boundary) + 6, $b->getSize());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesFilesArrayElement()
    {
        new MultipartPostBody([['foo' => 'bar']]);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresFileHasName()
    {
        new MultipartPostBody([['contents' => 'bar']]);
    }

    public function testSerializesFields()
    {
        $b = new MultipartPostBody([
            [
                'name'     => 'foo',
                'contents' => 'bar'
            ],
            [
                'name' => 'baz',
                'contents' => 'bam'
            ]
        ], 'boundary');
        $this->assertEquals(
            "--boundary\r\nContent-Disposition: form-data; name=\"foo\"\r\nContent-Length: 3\r\n\r\n"
            . "bar\r\n--boundary\r\nContent-Disposition: form-data; name=\"baz\"\r\nContent-Length: 3"
            . "\r\n\r\nbam\r\n--boundary--\r\n", (string) $b);
    }

    public function testSerializesFiles()
    {
        $f1 = Psr7\FnStream::decorate(Psr7\stream_for('foo'), [
            'getMetadata' => function () {
                return '/foo/bar.txt';
            }
        ]);

        $f2 = Psr7\FnStream::decorate(Psr7\stream_for('baz'), [
            'getMetadata' => function () {
                return '/foo/baz.jpg';
            }
        ]);

        $f3 = Psr7\FnStream::decorate(Psr7\stream_for('bar'), [
            'getMetadata' => function () {
                return '/foo/bar.gif';
            }
        ]);

        $b = new MultipartPostBody([
            [
                'name'     => 'foo',
                'contents' => $f1
            ],
            [
                'name' => 'qux',
                'contents' => $f2
            ],
            [
                'name'     => 'qux',
                'contents' => $f3
            ],
        ], 'boundary');

        $expected = <<<EOT
--boundary
Content-Disposition: form-data; name="foo"; filename="bar.txt"
Content-Length: 3
Content-Type: text/plain

foo
--boundary
Content-Disposition: form-data; name="qux"; filename="baz.jpg"
Content-Length: 3
Content-Type: image/jpeg

baz
--boundary
Content-Disposition: form-data; name="qux"; filename="bar.gif"
Content-Length: 3
Content-Type: image/gif

bar
--boundary--

EOT;

        $this->assertEquals($expected, str_replace("\r", '', $b));
    }

    public function testSerializesFilesWithCustomHeaders()
    {
        $f1 = Psr7\FnStream::decorate(Psr7\stream_for('foo'), [
            'getMetadata' => function () {
                return '/foo/bar.txt';
            }
        ]);

        $b = new MultipartPostBody([
            [
                'name' => 'foo',
                'contents' => $f1,
                'headers'  => [
                    'x-foo' => 'bar',
                    'content-disposition' => 'custom'
                ]
            ]
        ], 'boundary');

        $expected = <<<EOT
--boundary
x-foo: bar
content-disposition: custom
Content-Length: 3
Content-Type: text/plain

foo
--boundary--

EOT;

        $this->assertEquals($expected, str_replace("\r", '', $b));
    }

    public function testSerializesFilesWithCustomHeadersAndMultipleValues()
    {
        $f1 = Psr7\FnStream::decorate(Psr7\stream_for('foo'), [
            'getMetadata' => function () {
                return '/foo/bar.txt';
            }
        ]);

        $f2 = Psr7\FnStream::decorate(Psr7\stream_for('baz'), [
            'getMetadata' => function () {
                return '/foo/baz.jpg';
            }
        ]);

        $b = new MultipartPostBody([
            [
                'name'     => 'foo',
                'contents' => $f1,
                'headers'  => [
                    'x-foo' => 'bar',
                    'content-disposition' => 'custom'
                ]
            ],
            [
                'name'     => 'foo',
                'contents' => $f2,
                'headers'  => ['content-type' => 'custom'],
            ]
        ], 'boundary');

        $expected = <<<EOT
--boundary
x-foo: bar
content-disposition: custom
Content-Length: 3
Content-Type: text/plain

foo
--boundary
content-type: custom
Content-Disposition: form-data; name="foo"; filename="baz.jpg"
Content-Length: 3

baz
--boundary--

EOT;

        $this->assertEquals($expected, str_replace("\r", '', $b));
    }
}
