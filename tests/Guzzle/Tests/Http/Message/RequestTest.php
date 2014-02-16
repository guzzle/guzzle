<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Common\Emitter;
use Guzzle\Http\Message\Request;
use Guzzle\Stream\Stream;
use Guzzle\Url\QueryString;

/**
 * @covers Guzzle\Http\Message\Request
 */
class RequestTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructorInitializesMessage()
    {
        $r = new Request('PUT', '/test', ['test' => '123'], Stream::factory('foo'));
        $this->assertEquals('PUT', $r->getMethod());
        $this->assertEquals('/test', $r->getUrl());
        $this->assertEquals('123', $r->getHeader('test'));
        $this->assertEquals('foo', $r->getBody());
    }

    public function testConstructorInitializesMessageWithProtocolVersion()
    {
        $r = new Request('GET', '', [], null, ['protocol_version' => 10]);
        $this->assertEquals(10, $r->getProtocolVersion());
    }

    public function testConstructorInitializesMessageWithEmitter()
    {
        $e = new Emitter();
        $r = new Request('GET', '', [], null, ['emitter' => $e]);
        $this->assertSame($r->getEmitter(), $e);
    }

    public function testCloneIsDeep()
    {
        $r = new Request('GET', '/test', ['foo' => 'baz'], Stream::factory('foo'));
        $r2 = clone $r;

        $this->assertNotSame($r->getEmitter(), $r2->getEmitter());
        $this->assertEquals('foo', $r2->getBody());

        $r->getConfig()->set('test', 123);
        $this->assertFalse($r2->getConfig()->hasKey('test'));

        $r->setPath('/abc');
        $this->assertEquals('/test', $r2->getPath());
    }

    public function testCastsToString()
    {
        $r = new Request('GET', 'http://test.com/test', ['foo' => 'baz'], Stream::factory('body'));
        $s = explode("\r\n", (string) $r);
        $this->assertEquals("GET /test HTTP/1.1", $s[0]);
        $this->assertContains('Host: test.com', $s);
        $this->assertContains('Content-Length: 4', $s);
        $this->assertContains('foo: baz', $s);
        $this->assertContains('', $s);
        $this->assertContains('body', $s);
    }

    public function testSettingBodyWithNoDeterminableContentLengthAppliesTransferEncodingChunked()
    {
        $r = new Request('GET', '/');
        $s = $this->getMockBuilder('Guzzle\Stream\StreamInterface')
            ->setMethods(['getSize'])
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('getSize')
            ->will($this->returnValue(null));
        $r->setBody($s);
        $this->assertEquals('chunked', $r->getHeader('Transfer-Encoding'));
    }

    public function testSettingUrlOverridesHostHeaders()
    {
        $r = new Request('GET', 'http://test.com/test');
        $r->setUrl('https://baz.com/bar');
        $this->assertEquals('baz.com', $r->getHost());
        $this->assertEquals('baz.com', $r->getHeader('Host'));
        $this->assertEquals('/bar', $r->getPath());
        $this->assertEquals('https', $r->getScheme());
    }

    public function testQueryIsMutable()
    {
        $r = new Request('GET', 'http://www.foo.com?baz=bar');
        $this->assertEquals('baz=bar', $r->getQuery());
        $this->assertInstanceOf('Guzzle\Url\QueryString', $r->getQuery());
        $r->getQuery()->set('hi', 'there');
        $this->assertEquals('/?baz=bar&hi=there', $r->getResource());
    }

    public function testQueryCanChange()
    {
        $r = new Request('GET', 'http://www.foo.com?baz=bar');
        $r->setQuery(new QueryString(['foo' => 'bar']));
        $this->assertEquals('foo=bar', $r->getQuery());
    }

    public function testCanChangeMethod()
    {
        $r = new Request('GET', 'http://www.foo.com');
        $r->setMethod('put');
        $this->assertEquals('PUT', $r->getMethod());
    }

    public function testCanChangeSchemeWithPort()
    {
        $r = new Request('GET', 'http://www.foo.com:80');
        $r->setScheme('https');
        $this->assertEquals('https://www.foo.com', $r->getUrl());
    }

    public function testCanChangeScheme()
    {
        $r = new Request('GET', 'http://www.foo.com');
        $r->setScheme('https');
        $this->assertEquals('https://www.foo.com', $r->getUrl());
    }

    public function testCanChangeHost()
    {
        $r = new Request('GET', 'http://www.foo.com:222');
        $r->setHost('goo');
        $this->assertEquals('http://goo:222', $r->getUrl());
        $this->assertEquals('goo:222', $r->getHeader('host'));
        $r->setHost('goo:80');
        $this->assertEquals('http://goo', $r->getUrl());
        $this->assertEquals('goo', $r->getHeader('host'));
    }
}
