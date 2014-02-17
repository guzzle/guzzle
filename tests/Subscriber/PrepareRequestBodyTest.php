<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Subscriber\PrepareRequestBody;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\NoSeekStream;
use GuzzleHttp\Stream\Stream;

/**
 * @covers GuzzleHttp\Subscriber\PrepareRequestBody
 */
class PrepareRequestBodyTest extends \PHPUnit_Framework_TestCase
{
    public function testIgnoresRequestsWithNoBody()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $s->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Expect'));
    }

    public function testAppliesPostBody()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $p = $this->getMockBuilder('GuzzleHttp\Message\Post\PostBody')
            ->setMethods(['applyRequestHeaders'])
            ->getMockForAbstractClass();
        $p->expects($this->once())
            ->method('applyRequestHeaders');
        $t->getRequest()->setBody($p);
        $s->onRequestBeforeSend(new BeforeEvent($t));
    }

    public function testAddsExpectHeaderWithTrue()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', true);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertEquals('100-Continue', $t->getRequest()->getHeader('Expect'));
    }

    public function testAddsExpectHeaderBySize()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', 2);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertTrue($t->getRequest()->hasHeader('Expect'));
    }

    public function testDoesNotAddExpectHeaderBySize()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $t->getRequest()->getConfig()->set('expect', 10);
        $t->getRequest()->setBody(Stream::factory('foo'));
        $s->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Expect'));
    }

    public function testAddsExpectHeaderForNonSeekable()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $t->getRequest()->setBody(new NoSeekStream(Stream::factory('foo')));
        $s->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertTrue($t->getRequest()->hasHeader('Expect'));
    }

    public function testRemovesContentLengthWhenSendingWithChunked()
    {
        $s = new PrepareRequestBody();
        $t = $this->getTrans();
        $t->getRequest()->setBody(Stream::factory('foo'));
        $t->getRequest()->setHeader('Transfer-Encoding', 'chunked');
        $s->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertFalse($t->getRequest()->hasHeader('Content-Length'));
    }

    private function getTrans()
    {
        return new Transaction(new Client(), new Request('PUT', '/'));
    }
}
