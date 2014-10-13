<?php
namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Message\Response;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\NoSeekStream;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Prepare;

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
        $this->assertFalse($t->request->hasHeader('Expect'));
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
        $t->request->setBody($p);
        $s->onBefore(new BeforeEvent($t));
    }

    public function testAddsExpectHeaderWithTrue()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->getConfig()->set('expect', true);
        $t->request->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals('100-Continue', $t->request->getHeader('Expect'));
    }

    public function testAddsExpectHeaderBySize()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->getConfig()->set('expect', 2);
        $t->request->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertTrue($t->request->hasHeader('Expect'));
    }

    public function testDoesNotModifyExpectHeaderIfPresent()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->setHeader('Expect', 'foo');
        $t->request->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals('foo', $t->request->getHeader('Expect'));
    }

    public function testDoesAddExpectHeaderWhenSetToFalse()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->getConfig()->set('expect', false);
        $t->request->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->request->hasHeader('Expect'));
    }

    public function testDoesNotAddExpectHeaderBySize()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->getConfig()->set('expect', 10);
        $t->request->setBody(Stream::factory('foo'));
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->request->hasHeader('Expect'));
    }

    public function testAddsExpectHeaderForNonSeekable()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->setBody(new NoSeekStream(Stream::factory('foo')));
        $s->onBefore(new BeforeEvent($t));
        $this->assertTrue($t->request->hasHeader('Expect'));
    }

    public function testRemovesContentLengthWhenSendingWithChunked()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->setBody(Stream::factory('foo'));
        $t->request->setHeader('Transfer-Encoding', 'chunked');
        $s->onBefore(new BeforeEvent($t));
        $this->assertFalse($t->request->hasHeader('Content-Length'));
    }

    public function testUsesProvidedContentLengthAndRemovesXferEncoding()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->setBody(Stream::factory('foo'));
        $t->request->setHeader('Content-Length', '3');
        $t->request->setHeader('Transfer-Encoding', 'chunked');
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals(3, $t->request->getHeader('Content-Length'));
        $this->assertFalse($t->request->hasHeader('Transfer-Encoding'));
    }

    public function testSetsContentTypeIfPossibleFromStream()
    {
        $body = $this->getMockBody();
        $sub = new Prepare();
        $t = $this->getTrans();
        $t->request->setBody($body);
        $sub->onBefore(new BeforeEvent($t));
        $this->assertEquals(
            'image/jpeg',
            $t->request->getHeader('Content-Type')
        );
        $this->assertEquals(4, $t->request->getHeader('Content-Length'));
    }

    public function testDoesNotOverwriteExistingContentType()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->setBody($this->getMockBody());
        $t->request->setHeader('Content-Type', 'foo/baz');
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals(
            'foo/baz',
            $t->request->getHeader('Content-Type')
        );
    }

    public function testSetsContentLengthIfPossible()
    {
        $s = new Prepare();
        $t = $this->getTrans();
        $t->request->setBody($this->getMockBody());
        $s->onBefore(new BeforeEvent($t));
        $this->assertEquals(4, $t->request->getHeader('Content-Length'));
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

    public function testContentLengthIntegrationTest()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $client = new Client(['base_url' => Server::$url]);
        $this->assertEquals(200, $client->put('/', [
            'body' => 'test'
        ])->getStatusCode());
        $request = Server::received(true)[0];
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('4', $request->getHeader('Content-Length'));
        $this->assertEquals('test', (string) $request->getBody());
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
