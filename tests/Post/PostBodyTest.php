<?php

namespace GuzzleHttp\Tests\Post;

use GuzzleHttp\Post\PostBody;
use GuzzleHttp\Message\Request;
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
        $this->assertContains('multipart/form-data', (string) $m->getHeader('Content-Type'));
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
        $this->assertContains('multipart/form-data', (string) $m->getHeader('Content-Type'));
        $this->assertTrue($m->hasHeader('Content-Length'));
    }

    public function testApplyingWithFieldsAddsMultipartUpload()
    {
        $b = new PostBody();
        $b->setField('foo', 'bar');
        $this->assertEquals(['foo' => 'bar'], $b->getFields());
        $m = new Request('POST', '/');
        $b->applyRequestHeaders($m);
        $this->assertContains('application/x-www-form', (string) $m->getHeader('Content-Type'));
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
      $this->assertContains('multipart/form-data', (string) $m->getHeader('Content-Type'));
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
        $this->assertTrue($b->close());
        $this->assertEquals('', $b->read(10));
    }

    public function testCreatesMultipartUploadWithMultiFields()
    {
        $b = new PostBody();
        $b->setField('testing', ['baz', 'bar']);
        $b->addFile(new PostFile('foo', fopen(__FILE__, 'r')));
        $s = (string) $b;
        $this->assertContains(file_get_contents(__FILE__), $s);
        $this->assertContains('testing=bar', $s);
    }
}
