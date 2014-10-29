<?php
namespace GuzzleHttp\Tests\Post;

use GuzzleHttp\Message\Request;
use GuzzleHttp\Post\PostBody;
use GuzzleHttp\Post\PostFile;
use GuzzleHttp\Query;

/**
 * @covers GuzzleHttp\Post\PostBody
 */
class PostBodyTest extends \PHPUnit_Framework_TestCase
{
    public function testWrapsBasicStreamFunctionality()
    {
        $b = new PostBody();
        $this->assertTrue($b->isSeekable());
        $this->assertTrue($b->isReadable());
        $this->assertFalse($b->isWritable());
        $this->assertFalse($b->write('foo'));
    }

    public function testApplyingWithNothingDoesNothing()
    {
        $b = new PostBody();
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertFalse($m->hasHeader('Content-Length'));
        $this->assertFalse($m->hasHeader('Content-Type'));
    }

    public function testCanForceMultipartUploadsWhenApplying()
    {
        $b = new PostBody();
        $b->forceMultipartUpload(true);
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains(
            'multipart/form-data',
            $m->getHeader('Content-Type')
        );
    }

    public function testApplyingWithFilesAddsMultipartUpload()
    {
        $b = new PostBody();
        $p = new PostFile('foo', fopen(__FILE__, 'r'));
        $b->addFile($p);
        $this->assertEquals([$p], $b->getFiles());
        $this->assertNull($b->getFile('missing'));
        $this->assertSame($p, $b->getFile('foo'));
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains(
            'multipart/form-data',
            $m->getHeader('Content-Type')
        );
        $this->assertTrue($m->hasHeader('Content-Length'));
    }

    public function testApplyingWithFieldsAddsMultipartUpload()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $this->assertEquals(['foo' => 'bar'], $b->getFields());
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains(
            'application/x-www-form',
            $m->getHeader('Content-Type')
        );
        $this->assertTrue($m->hasHeader('Content-Length'));
    }

    public function testMultipartWithNestedFields()
    {
        $b = new PostBody();
        $b->setField('foo', ['bar' => 'baz']);
        $b->forceMultipartUpload(true);
        $this->assertEquals(['foo' => ['bar' => 'baz']], $b->getFields());
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains(
            'multipart/form-data',
            $m->getHeader('Content-Type')
        );
        $this->assertTrue($m->hasHeader('Content-Length'));
        $contents = $b->getContents();
        $this->assertContains('name="foo[bar]"', $contents);
        $this->assertNotContains('name="foo"', $contents);
    }

    public function testCountProvidesFieldsAndFiles()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $b->addFile(new PostFile('foo', fopen(__FILE__, 'r')));
        $this->assertEquals(2, count($b));
        $b->clearFiles();
        $b->removeField('foo');
        $this->assertEquals(0, count($b));
        $this->assertEquals([], $b->getFiles());
        $this->assertEquals([], $b->getFields());
    }

    public function testHasFields()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $b->setField('baz', '123');
        $this->assertEquals('bar', $b->getField('foo'));
        $this->assertEquals('123', $b->getField('baz'));
        $this->assertNull($b->getField('ahh'));
        $this->assertTrue($b->hasField('foo'));
        $this->assertFalse($b->hasField('test'));
        $b->replaceFields(['abc' => '123']);
        $this->assertFalse($b->hasField('foo'));
        $this->assertTrue($b->hasField('abc'));
    }

    public function testConvertsFieldsToQueryStyleBody()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $b->setField('baz', '123');
        $this->assertEquals('foo=bar&baz=123', $b);
        $this->assertEquals(15, $b->getSize());
        $b->seek(0);
        $this->assertEquals('foo=bar&baz=123', $b->getContents());
        $b->seek(0);
        $this->assertEquals('foo=bar&baz=123', $b->read(1000));
        $this->assertEquals(15, $b->tell());
        $this->assertTrue($b->eof());
    }

    public function testCanSpecifyQueryAggregator()
    {
        $b = new PostBody();
        $b->setField('foo', ['baz', 'bar']);
        $this->assertEquals('foo%5B0%5D=baz&foo%5B1%5D=bar', (string) $b);
        $b = new PostBody();
        $b->setField('foo', ['baz', 'bar']);
        $agg = Query::duplicateAggregator();
        $b->setAggregator($agg);
        $this->assertEquals('foo=baz&foo=bar', (string) $b);
    }

    public function testDetachesAndCloses()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $b->detach();
        $b->close();
        $this->assertEquals('', $b->read(10));
    }

    public function testDetachesWhenBodyIsPresent()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $b->getContents();
        $b->detach();
    }

    public function testFlushAndMetadataPlaceholders()
    {
        $b = new PostBody();
        $this->assertEquals([], $b->getMetadata());
        $this->assertNull($b->getMetadata('foo'));
    }

    public function testCreatesMultipartUploadWithMultiFields()
    {
        $b = new PostBody();
        $b->setField('testing', ['baz', 'bar']);
        $b->setField('other', 'hi');
        $b->setField('third', 'there');
        $b->addFile(new PostFile('foo', fopen(__FILE__, 'r')));
        $s = (string) $b;
        $this->assertContains(file_get_contents(__FILE__), $s);
        $this->assertContains('testing=bar', $s);
        $this->assertContains(
            'Content-Disposition: form-data; name="third"',
            $s
        );
        $this->assertContains(
            'Content-Disposition: form-data; name="other"',
            $s
        );
    }

    public function testMultipartWithBase64Fields()
    {
        $b = new PostBody();
        $b->setField('foo64', '/xA2JhWEqPcgyLRDdir9WSRi/khpb2Lh3ooqv+5VYoc=');
        $b->forceMultipartUpload(true);
        $this->assertEquals(
            ['foo64' => '/xA2JhWEqPcgyLRDdir9WSRi/khpb2Lh3ooqv+5VYoc='],
            $b->getFields()
        );
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains(
            'multipart/form-data',
            $m->getHeader('Content-Type')
        );
        $this->assertTrue($m->hasHeader('Content-Length'));
        $contents = $b->getContents();
        $this->assertContains('name="foo64"', $contents);
        $this->assertContains(
            '/xA2JhWEqPcgyLRDdir9WSRi/khpb2Lh3ooqv+5VYoc=',
            $contents
        );
    }

    public function testMultipartWithAmpersandInValue()
    {
        $b = new PostBody();
        $b->setField('a', 'b&c=d');
        $b->forceMultipartUpload(true);
        $this->assertEquals(['a' => 'b&c=d'], $b->getFields());
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains(
            'multipart/form-data',
            $m->getHeader('Content-Type')
        );
        $this->assertTrue($m->hasHeader('Content-Length'));
        $contents = $b->getContents();
        $this->assertContains('name="a"', $contents);
        $this->assertContains('b&c=d', $contents);
    }

    /**
     * @expectedException \GuzzleHttp\Stream\Exception\CannotAttachException
     */
    public function testCannotAttach()
    {
        $b = new PostBody();
        $b->attach('foo');
    }

    public function testDoesNotOverwriteExistingHeaderForUrlencoded()
    {
        $m = new Request('POST', 'http://foo.com', [
            'content-type' => 'application/x-www-form-urlencoded; charset=utf-8'
        ]);
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $b->applyRequestHeaders($m);
        $this->assertEquals(
            'application/x-www-form-urlencoded; charset=utf-8',
            $m->getHeader('Content-Type')
        );
    }
}
