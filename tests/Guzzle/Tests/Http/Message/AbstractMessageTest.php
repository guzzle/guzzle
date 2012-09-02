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
        $this->assertSame($this->mock, $this->mock->setHeaders(array(
            'a' => 'b',
            'c' => 'd'
        )));

        $this->assertEquals(array(
            'a' => array('b'),
            'c' => array('d')
        ), $this->mock->getHeaders()->getAll());

        foreach ($this->mock->getHeaders(true) as $key => $value) {
            $this->assertInternalType('string', $key);
            $this->assertInstanceOf('Guzzle\Http\Message\Header', $value);
        }
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
            'a: b!e',
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
        $this->mock->setHeader('test', $string);
        $this->assertEquals($result, $this->mock->getTokenizedHeader('test', $token)->getAll());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setTokenizedHeader
     * @dataProvider tokenizedHeaderProvider
     */
    public function testConvertsArrayToTokenizedHeader($string, $token, $result)
    {
        $this->mock->setTokenizedHeader('test', $result, $token);
        $this->assertEquals($string, $this->mock->getHeader('test'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setTokenizedHeader
     * @expectedException InvalidArgumentException
     */
    public function testTokenizedHeaderMustBeArrayToSet()
    {
        $this->mock->setTokenizedHeader('test', false);
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getTokenizedHeader
     */
    public function testReturnsNullWhenTokenizedHeaderNotFound()
    {
        $this->assertNull($this->mock->getTokenizedHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::getTokenizedHeader
     */
    public function testMultipleTokenizedHeadersAreCombined()
    {
        $this->mock->addHeader('test', 'ISO-8859-1,utf-8;q=0.7,*;q=0.7');
        $this->mock->addHeader('test', 'foo;q=123,*;q=456;q=0.7');
        $this->mock->addHeader('Content-Length', 0);

        $this->assertEquals(array(
            0 => 'ISO-8859-1,utf-8',
            'q' => array('0.7,*', '0.7', '123,*', '456'),
            2 => 'foo',
        ), $this->mock->getTokenizedHeader('test')->getAll());
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
     * @covers Guzzle\Http\Message\AbstractMessage::addHeader
     * @covers Guzzle\Http\Message\AbstractMessage::getHeader
     */
    public function testAddingHeadersWithMultipleValuesUsesCaseInsensitiveKey()
    {
        $this->mock->addHeaders(array(
            'test' => '123',
            'Test' => '456'
        ));
        $this->mock->addHeader('TEST', '789');

        $headers = array(
            'test' => array('123'),
            'Test' => array('456'),
            'TEST' => array('789'),
        );
        $header = $this->mock->getHeader('test');
        $this->assertInstanceOf('Guzzle\Http\Message\Header', $header);
        $this->assertSame($header, $this->mock->getHeader('TEST'));
        $this->assertSame($header, $this->mock->getHeader('TeSt'));

        $this->assertEquals($headers, $header->raw());
    }

    /**
     * @covers Guzzle\Http\Message\AbstractMessage::setHeader
     */
    public function testSettingHeadersUsesCaseInsensitiveKey()
    {
        $this->mock->setHeader('test', '123');
        $this->mock->setHeader('TEST', '456');
        $this->assertEquals('456', $this->mock->getHeader('test'));
        $this->assertEquals('456', $this->mock->getHeader('TEST'));
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
        $this->assertEquals(array('123', '456', '789', 'abc'), $header->toArray());
        $this->mock->addHeader('Test', 'abc');

        // Add a header of a different name
        $this->assertEquals(array(
            'test' => array('123', '456', '789'),
            'Test' => array('abc', 'abc')
        ), $this->mock->getHeader('test')->raw());
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
