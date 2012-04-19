<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\Response;

class AbstractMessageTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Request Request object
     */
    private $request;

    /**
     * Setup
     */
    public function setUp()
    {
        parent::setUp();

        $this->request = new Request('GET', 'http://www.guzzle-project.com/');
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getParams
     */
    public function testGetParams()
    {
        $this->assertInstanceOf('Guzzle\\Common\\Collection', $this->request->getParams());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::addHeaders
     */
    public function testAddHeaders()
    {
        $this->request->setHeader('A', 'B');

        $this->assertEquals($this->request, $this->request->addHeaders(array(
            'X-Data' => '123'
        )));

        $this->assertTrue($this->request->hasHeader('X-Data') !== false);
        $this->assertTrue($this->request->hasHeader('A') !== false);
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getHeaders
     */
    public function testGetHeader()
    {
        $this->request->setHeader('Test', '123');
        $this->assertEquals('123', $this->request->getHeader('Test'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getHeaders
     * @covers Guzzle\Http\Message\AbstractMessage::setHeaders
     */
    public function testGetHeaders()
    {
        $this->assertEquals($this->request, $this->request->setHeaders(array(
            'a' => 'b',
            'c' => 'd'
        )));

        $this->assertEquals(array(
            'a' => 'b',
            'c' => 'd'
        ), $this->request->getHeaders()->getAll());

        $this->assertEquals(array(
            'a' => 'b'
        ), $this->request->getHeaders(array('a'))->getAll());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::hasHeader
     */
    public function testHasHeader()
    {
        $this->assertFalse($this->request->hasHeader('Foo'));
        $this->request->setHeader('Foo', 'Bar');
        $this->assertEquals(true, $this->request->hasHeader('Foo'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::hasHeader
     */
    public function testHasHeaderSearch()
    {
        $this->assertFalse($this->request->hasHeader('Foo'));
        $this->request->setHeader('Foo', 'Bar');
        $this->assertEquals(true, $this->request->hasHeader('Foo', 1));
        $this->assertEquals(true, $this->request->hasHeader('/Foo/', 2));
        $this->assertEquals(false, $this->request->hasHeader('bar', 1));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::removeHeader
     * @covers Guzzle\Http\Message\AbstractMessage::setHeader
     */
    public function testRemoveHeader()
    {
        $this->request->setHeader('Foo', 'Bar');
        $this->assertEquals(true, $this->request->hasHeader('Foo'));
        $this->request->removeHeader('Foo');
        $this->assertFalse($this->request->hasHeader('Foo'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage
     */
    public function testHoldsCacheControlDirectives()
    {
        $r = $this->request;

        // Set a directive using a header
        $r->setHeader('Cache-Control', 'max-age=100');
        $this->assertEquals(100, $r->getCacheControlDirective('max-age'));

        // Set a header using the directive and check that the header was updated
        $this->assertSame($r, $r->addCacheControlDirective('max-age', 80));
        $this->assertEquals(80, $r->getCacheControlDirective('max-age'));
        $this->assertEquals('max-age=80', $r->getHeader('Cache-Control'));

        // Remove the directive
        $this->assertEquals($r, $r->removeCacheControlDirective('max-age'));
        $this->assertEquals('', $r->getHeader('Cache-Control'));
        $this->assertEquals(null, $r->getCacheControlDirective('max-age'));
        // Remove a non-existent directive
        $this->assertEquals($r, $r->removeCacheControlDirective('max-age'));

        // Has directive
        $this->assertFalse($r->hasCacheControlDirective('max-age'));
        $r->addCacheControlDirective('must-revalidate');
        $this->assertTrue($r->hasCacheControlDirective('must-revalidate'));

        // Make sure that it works with multiple Cache-Control headers
        $r->setHeader('Cache-Control', 'must-revalidate, max-age=100');
        $r->addHeaders(array(
            'Cache-Control' => 'no-cache'
        ));

        $this->assertEquals(true, $r->getCacheControlDirective('no-cache'));
        $this->assertEquals(true, $r->getCacheControlDirective('must-revalidate'));
        $this->assertEquals(100, $r->getCacheControlDirective('max-age'));
    }

    public function tokenizedHeaderProvider()
    {
        return array(
            array('ISO-8859-1,utf-8;q=0.7,*;q=0.7"', ';', array(
                'ISO-8859-1,utf-8',
                'q' => array('0.7,*', '0.7"')
            )),
            array('gzip,deflate', ',', array('gzip', 'deflate')),
            array('en-us,en;q=0.5', ';', array(
                'en-us,en',
                'q' => '0.5'
            ))
        );
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getTokenizedHeader
     * @dataProvider tokenizedHeaderProvider
     */
    public function testConvertsTokenizedHeadersToArray($string, $token, $result)
    {
        $r = $this->request;
        $r->setHeader('test', $string);
        $this->assertEquals($result, $r->getTokenizedHeader('test', $token)->getAll());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setTokenizedHeader
     * @dataProvider tokenizedHeaderProvider
     */
    public function testConvertsArrayToTokenizedHeader($string, $token, $result)
    {
        $r = $this->request;
        $r->setTokenizedHeader('test', $result, $token);
        $this->assertEquals($string, $r->getHeader('test'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setTokenizedHeader
     * @expectedException InvalidArgumentException
     */
    public function testTokenizedHeaderMustBeArrayToSet()
    {
        $r = $this->request;
        $r->setTokenizedHeader('test', false);
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getTokenizedHeader
     */
    public function testReturnsNullWhenTokenizedHeaderNotFound()
    {
        $r = $this->request;
        $this->assertNull($r->getTokenizedHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getTokenizedHeader
     */
    public function testMultipleTokenizedHeadersAreCombined()
    {
        $r = Response::factory(
            "HTTP/1.1 200 OK\r\n" .
            "test: ISO-8859-1,utf-8;q=0.7,*;q=0.7\"\r\n" .
            "test: foo;q=123,*;q=456;q=0.7\"\r\n" .
            "Content-Length: 0\r\n\r\n"
        );

        $this->assertEquals(array(
            0 => 'ISO-8859-1,utf-8',
            'q' => array('0.7,*', '0.7"', '123,*', '456'),
            2 => 'foo'
        ), $r->getTokenizedHeader('test')->getAll());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::addHeaders
     * @covers Guzzle\Http\Message\AbstractMessage::getHeader
     */
    public function testAddingHeadersWithMultipleValuesUsesCaseInsensitiveKey()
    {
        $response = new Response(200);
        $response->addHeaders(array(
            'test' => '123'
        ));
        $response->addHeaders(array(
            'Test' => '456'
        ));
        $response->addHeaders(array(
            'TEST' => '789'
        ));

        $headers = array(
            'test' => '123',
            'Test' => '456',
            'TEST' => '789'
        );

        $this->assertEquals($headers, $response->getHeader('test'));
        $this->assertEquals($headers, $response->getHeader('Test'));
        $this->assertEquals($headers, $response->getHeader('TEST'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setHeader
     */
    public function testSettingHeadersUsesCaseInsensitiveKey()
    {
        $response = new Response(200, array(
            'test' => '123'
        ));
        $response->setHeader('TEST', '456');
        $this->assertEquals('456', $response->getHeader('test'));
        $this->assertEquals('456', $response->getHeader('TEST'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::addHeaders
     */
    public function testAddingHeadersPreservesOriginalHeaderCase()
    {
        $response = new Response(200, array(
            'test' => '123'
        ));
        $response->addHeaders(array(
            'test' => '456'
        ));
        $response->addHeaders(array(
            'test' => '789'
        ));
        $this->assertEquals(array('123', '456', '789'), $response->getHeader('test'));
        $response->addHeaders(array(
            'Test' => 'abc'
        ));

        // Add a header of a different name
        $this->assertEquals(array(
            'test' => array('123', '456', '789'),
            'Test' => 'abc'
        ), $response->getHeader('test'));
    }
}