<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Subscriber\Prepare;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\NoSeekStream;
use GuzzleHttp\Stream\Stream;

/**
 * @covers GuzzleHttp\Subscriber\Prepare
 */
class PrepareTest extends \PHPUnit_Framework_TestCase
{
    public function testIgnoresRequestsWithNoBody()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Expect'));
    }

    public function testAppliesPostBody()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $p = $this->getMockBuilder('GuzzleHttp\Post\PostBody')
            ->setMethods(['applyRequestHeaders'])
            ->getMockForAbstractClass();
        $p->expects($this->once())
            ->method('applyRequestHeaders');
        $t->getRequest()->setBody($p);
        $s->onBefore(new BeforeEvent($t));
    }

    public function testAddsExpectHeaderWithTrue()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', true);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals('100-Continue', $t->getRequest()->getHeader('Expect'));
    }

    public function testAddsExpectHeaderBySize()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', 2);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertTrue($t->getRequest()->hasHeader('Expect'));
    }

    public function testDoesNotModifyExpectHeaderIfPresent()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setHeader('Expect', 'foo');
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals('foo', $t->getRequest()->getHeader('Expect'));
    }

    public function testDoesAddExpectHeaderWhenSetToFalse()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', false);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Expect'));
    }

    public function testDoesNotAddExpectHeaderBySize()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', 10);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Expect'));
    }

    public function testAddsExpectHeaderForNonSeekable()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setBody(new NoSeekStream(Stream::factory('foo')));
        $s->onBefore(new BeforeEvent($t));
        $this->assertTrue($t->getRequest()->hasHeader('Expect'));
    }

    public function testRemovesContentLengthWhenSendingWithChunked()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setBody(Stream::factory('foo'));
        $t->getRequest()->setHeader('Transfer-Encoding', 'chunked');
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Content-Length'));
    }

    public function testUsesProvidedContentLengthAndRemovesXferEncoding()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setBody(Stream::factory('foo'));
        $t->getRequest()->setHeader('Content-Length', '3');
        $t->getRequest()->setHeader('Transfer-Encoding', 'chunked');
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals(3, $t->getRequest()->getHeader('Content-Length'));
        $this->assertFalse($t->getRequest()->hasHeader('Transfer-Encoding'));
    }

    public function testSetsContentTypeIfPossibleFromStream()
    {
        $body = $this->getMockBody();
        $sub = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setBody($body);
        $sub->onBefore(new BeforeEvent($t));
        $this->assertEquals(
            'image/jpeg',
            $t->getRequest()->getHeader('Content-Type')
        );
        $this->assertEquals(4, $t->getRequest()->getHeader('Content-Length'));
    }

    public function testDoesNotOverwriteExistingContentType()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setBody($this->getMockBody());
        $t->getRequest()->setHeader('Content-Type', 'foo/baz');
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals(
            'foo/baz',
            $t->getRequest()->getHeader('Content-Type')
        );
    }

    public function testSetsContentLengthIfPossible()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->getRequest()->setBody($this->getMockBody());
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals(4, $t->getRequest()->getHeader('Content-Length'));
    }

    public function testSetsTransferEncodingChunkedIfNeeded()
    {
        $r = new Request('PUT', '/');
        $s = $this->getMockBuilder('GuzzleHttp\Stream\StreamInterface')
            ->setMethods(['getSize'])
            ->getMockForAbstractClass();
        $s->expects($this->exactly(2))
            ->method('getSize')
            ->will($this->returnValue(null));
        $r->setBody($s);
        $t = $this->getTrans($r);
        $s = new Prepare();
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals('chunked', $r->getHeader('Transfer-Encoding'));
    }

    private function getTrans($request = null)
    {
        return new Transaction(
            new Client(),
            $request ?: new Request('PUT', '/')
        );
    }

    /**
     * @return \GuzzleHttp\Stream\StreamInterface
     */
    private function getMockBody()
    {
        $s = $this->getMockBuilder('GuzzleHttp\Stream\MetadataStreamInterface')
            ->setMethods(['getMetadata', 'getSize'])
            ->getMockForAbstractClass();
        $s->expects($this->any())
            ->method('getMetadata')
            ->with('uri')
            ->will($this->returnValue('/foo/baz/bar.jpg'));
        $s->expects($this->exactly(2))
            ->method('getSize')
            ->will($this->returnValue(4));

        return $s;
    }
}
