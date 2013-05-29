<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\Header;
use Guzzle\Http\Message\Request;
use Guzzle\Common\Collection;

class AbstractMessageTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Request Request object
     */
    private $request;
    private $mock;

    /**
     * Setup
     */
    public function setUp()
    {
        parent::setUp();
        $this->mock = $this->getMockForAbstractClass('Guzzle\Http\Message\AbstractMessage');
    }

    public function tearDown()
    {
        $this->mock = $this->request = null;
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getParams
     */
    public function testGetParams()
    {
        $request = new Request('GET', 'http://example.com');
        $this->assertInstanceOf('Guzzle\\Common\\Collection', $request->getParams());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::addHeaders
     */
    public function testAddHeaders()
    {
        $this->mock->setHeader('A', 'B');

        $this->assertEquals($this->mock, $this->mock->addHeaders(array(
            'X-Data' => '123'
        )));

        $this->assertTrue($this->mock->hasHeader('X-Data') !== false);
        $this->assertTrue($this->mock->hasHeader('A') !== false);
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setHeader
     */
    public function testAllowsHeaderToSetAsHeader()
    {
        $h = new Header('A', 'B');
        $this->mock->setHeader('A', $h);
        $this->assertSame($h, $this->mock->getHeader('A'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getHeaders
     */
    public function testGetHeader()
    {
        $this->mock->setHeader('Test', '123');
        $this->assertEquals('123', $this->mock->getHeader('Test'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getHeaders
     * @covers Guzzle\Http\Message\AbstractMessage::getHeader
     * @covers Guzzle\Http\Message\AbstractMessage::setHeaders
     */
    public function testGetHeaders()
    {
        $this->assertSame($this->mock, $this->mock->setHeaders(array('a' => 'b', 'c' => 'd')));
        $h = $this->mock->getHeaders();
        $this->assertArrayHasKey('a', $h->toArray());
        $this->assertArrayHasKey('c', $h->toArray());
        $this->assertInstanceOf('Guzzle\Http\Message\Header\HeaderInterface', $h->get('a'));
        $this->assertInstanceOf('Guzzle\Http\Message\Header\HeaderInterface', $h->get('c'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getHeaderLines
     */
    public function testGetHeaderLinesUsesGlue()
    {
        $this->mock->setHeaders(array('a' => 'b', 'c' => 'd'));
        $this->mock->addHeader('a', 'e');
        $this->mock->getHeader('a')->setGlue('!');
        $this->assertEquals(array(
            'a: b! e',
            'c: d'
        ), $this->mock->getHeaderLines());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::hasHeader
     */
    public function testHasHeader()
    {
        $this->assertFalse($this->mock->hasHeader('Foo'));
        $this->mock->setHeader('Foo', 'Bar');
        $this->assertEquals(true, $this->mock->hasHeader('Foo'));
        $this->mock->setHeader('foo', 'yoo');
        $this->assertEquals(true, $this->mock->hasHeader('Foo'));
        $this->assertEquals(true, $this->mock->hasHeader('foo'));
        $this->assertEquals(false, $this->mock->hasHeader('bar'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::removeHeader
     * @covers Guzzle\Http\Message\AbstractMessage::setHeader
     */
    public function testRemoveHeader()
    {
        $this->mock->setHeader('Foo', 'Bar');
        $this->assertEquals(true, $this->mock->hasHeader('Foo'));
        $this->mock->removeHeader('Foo');
        $this->assertFalse($this->mock->hasHeader('Foo'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage
     */
    public function testHoldsCacheControlDirectives()
    {
        $mock = $this->mock;

        // Set a directive using a header
        $mock->setHeader('Cache-Control', 'max-age=100');
        $this->assertEquals(100, $mock->getCacheControlDirective('max-age'));

        // Set a header using the directive and check that the header was updated
        $this->assertSame($mock, $mock->addCacheControlDirective('max-age', 80));
        $this->assertEquals(80, $mock->getCacheControlDirective('max-age'));
        $this->assertEquals('max-age=80', $mock->getHeader('Cache-Control'));

        // Remove the directive
        $this->assertEquals($mock, $mock->removeCacheControlDirective('max-age'));
        $this->assertEquals('', $mock->getHeader('Cache-Control'));
        $this->assertEquals(null, $mock->getCacheControlDirective('max-age'));
        // Remove a non-existent directive
        $this->assertEquals($mock, $mock->removeCacheControlDirective('max-age'));

        // Has directive
        $this->assertFalse($mock->hasCacheControlDirective('max-age'));
        $mock->addCacheControlDirective('must-revalidate');
        $this->assertTrue($mock->hasCacheControlDirective('must-revalidate'));

        // Make sure that it works with multiple Cache-Control headers
        $mock->setHeader('Cache-Control', 'must-revalidate, max-age=100');
        $mock->addHeaders(array(
            'Cache-Control' => 'no-cache'
        ));

        $this->assertEquals(true, $mock->getCacheControlDirective('no-cache'));
        $this->assertEquals(true, $mock->getCacheControlDirective('must-revalidate'));
        $this->assertEquals(100, $mock->getCacheControlDirective('max-age'));
    }

    /**
     * In some rare cases. Like with the Twitter Search API:
     *
     *    http://search.twitter.com/search.json?q=from:mtdowling
     *
     * There is more than once Cache-Control added.
     * Twice the max-age for example.
     *
     * @covers Guzzle\Http\Message\AbstractMessage
     */
    public function testDoubleCacheControlDirectives()
    {
        $mock = $this->mock;
        $mock->setHeader('Cache-Control', 'max-age=15, must-revalidate, max-age=300');
        $this->assertEquals(300, $mock->getCacheControlDirective('max-age'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getHeader
     */
    public function testReturnsNullWhenHeaderIsNotFound()
    {
        $this->assertNull($this->mock->getHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::addHeaders
     */
    public function testAddingHeadersPreservesOriginalHeaderCase()
    {
        $this->mock->addHeaders(array(
            'test' => '123',
            'Test' => 'abc'
        ));
        $this->mock->addHeader('test', '456');
        $this->mock->addHeader('test', '789');

        $header = $this->mock->getHeader('test');
        $this->assertContains('123', $header->toArray());
        $this->assertContains('456', $header->toArray());
        $this->assertContains('789', $header->toArray());
        $this->assertContains('abc', $header->toArray());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage
     */
    public function testCanStoreEmptyHeaders()
    {
        $this->mock->setHeader('Content-Length', 0);
        $this->assertTrue($this->mock->hasHeader('Content-Length'));
        $this->assertEquals(0, $this->mock->getHeader('Content-Length', true));
    }
}
