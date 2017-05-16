<?php

namespace Guzzle\Tests\Plugin\Mock\RequestMatcher;

use Guzzle\Plugin\Mock\RequestMatcher\UriRequestMatcher;
use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Plugin\Mock\RequestMatcher\UriRequestMatcher
 */
class UriRequestMatcherTest extends GuzzleTestCase
{
    public function testInstanceOf()
    {
        $matcher = new UriRequestMatcher();

        $this->assertInstanceOf('Guzzle\Plugin\Mock\RequestMatcher\RequestMatcherInterface', $matcher);
    }

    public function testMatchesResponseObjectOnConstructor()
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('GET'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = $this->getMockBuilder('Guzzle\Http\Message\Response')->disableOriginalConstructor()->getMock();

        $matcher = new UriRequestMatcher('GET', array('http://example.com/foo' => $response));

        $this->assertSame($response, $matcher->match($request));
    }

    /**
     * @dataProvider partialURIProvider
     */
    public function testMatchesResponseObjectOnConstructorWithAPartialURI($uri)
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('GET'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = $this->getMockBuilder('Guzzle\Http\Message\Response')->disableOriginalConstructor()->getMock();

        $matcher = new UriRequestMatcher('GET', array($uri => $response));

        $this->assertSame($response, $matcher->match($request));
    }

    public function partialURIProvider()
    {
        return array(
            array('example.com'),
            array('/foo'),
        );
    }

    public function testDoesNotMatchResponseObjectOnConstructorWithADifferentMethod()
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('POST'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = $this->getMockBuilder('Guzzle\Http\Message\Response')->disableOriginalConstructor()->getMock();

        $matcher = new UriRequestMatcher('GET', array('http://example.com/foo' => $response));

        $this->assertNull($matcher->match($request));
    }

    public function testDoesNotMatchesResponseObjectOnConstructorWithADifferentURI()
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('GET'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = $this->getMockBuilder('Guzzle\Http\Message\Response')->disableOriginalConstructor()->getMock();

        $matcher = new UriRequestMatcher('GET', array('foo.com' => $response));

        $this->assertNull($matcher->match($request));
    }

    public function testMatchesRegisteredResponseObject()
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('GET'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = $this->getMockBuilder('Guzzle\Http\Message\Response')->disableOriginalConstructor()->getMock();

        $matcher = new UriRequestMatcher();
        $matcher->registerUri('http://example.com/foo', $response);

        $this->assertSame($response, $matcher->match($request));
    }

    public function testMatchesRegisteredStringResponse()
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('GET'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = <<<EOT
HTTP/1.1 200 OK
Date: Fri, 31 Dec 1999 23:59:59 GMT
Content-Type: text/plain
Content-Length: 8

Foo bar.
EOT;

        $matcher = new UriRequestMatcher();
        $matcher->registerUri('http://example.com/foo', $response);

        $this->assertSame('Foo bar.', (string) $matcher->match($request)->getBody(true));
    }

    public function testMatchesRegisteredFileResponse()
    {
        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->any())->method('getMethod')->will($this->returnValue('GET'));
        $request->expects($this->any())->method('getUrl')->will($this->returnValue('http://example.com/foo'));

        $response = __DIR__ . '/Fixtures/response.txt';

        $matcher = new UriRequestMatcher();
        $matcher->registerUri('http://example.com/foo', $response);

        $this->assertSame('Foo bar.', (string) $matcher->match($request)->getBody(true));
    }
}
