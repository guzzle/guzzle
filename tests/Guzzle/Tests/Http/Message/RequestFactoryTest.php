<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\Url;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\QueryString;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class HttpRequestFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Message\RequestFactory::create
     * @covers Guzzle\Http\Message\RequestFactory::get
     */
    public function testCreatesNewGetRequests()
    {
        $request = RequestFactory::get('http://www.google.com/');
        $this->assertInstanceOf('Guzzle\\Http\\Message\\MessageInterface', $request);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\RequestInterface', $request);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Request', $request);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http', $request->getScheme());
        $this->assertEquals('http://www.google.com/', $request->getUrl());
        $this->assertEquals('www.google.com', $request->getHost());
        $this->assertEquals('/', $request->getPath());
        $this->assertEquals('/', $request->getResourceUri());
        
        // Create a GET request with a custom receiving body
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $b = EntityBody::factory('');
        $request = RequestFactory::get($this->getServer()->getUrl(), null, $b);
        $response = $request->send();
        $this->assertSame($b, $response->getBody());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::create
     * @covers Guzzle\Http\Message\RequestFactory::put
     */
    public function testCreatesPutRequests()
    {
        // Test using a string
        $request = RequestFactory::put('http://www.google.com/path?q=1&v=2', null, 'Data');
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('http', $request->getScheme());
        $this->assertEquals('http://www.google.com/path?q=1&v=2', $request->getUrl());
        $this->assertEquals('www.google.com', $request->getHost());
        $this->assertEquals('/path', $request->getPath());
        $this->assertEquals('/path?q=1&v=2', $request->getResourceUri());
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $request->getBody());
        $this->assertEquals('Data', (string) $request->getBody());
        unset($request);

        // Test using an EntityBody
        $request = RequestFactory::put('http://www.google.com/path?q=1&v=2', null, EntityBody::factory('Data'));
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('Data', (string) $request->getBody());

        // Test using a resource
        $resource = fopen('php://temp', 'w+');
        fwrite($resource, 'Data');
        $request = RequestFactory::put('http://www.google.com/path?q=1&v=2', null, $resource);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('Data', (string) $request->getBody());

        // Test using an object that can be cast as a string
        $request = RequestFactory::put('http://www.google.com/path?q=1&v=2', null, Url::factory('http://www.example.com/'));
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('http://www.example.com/', (string) $request->getBody());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::head
     * @covers Guzzle\Http\Message\RequestFactory::delete
     */
    public function testCreatesHeadAndDeleteRequests()
    {
        $request = RequestFactory::delete('http://www.test.com/');
        $this->assertEquals('DELETE', $request->getMethod());
        $request = RequestFactory::head('http://www.test.com/');
        $this->assertEquals('HEAD', $request->getMethod());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::options
     */
    public function testCreatesOptionsRequests()
    {
        $request = RequestFactory::options('http://www.example.com/');
        $this->assertEquals('OPTIONS', $request->getMethod());
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Request', $request);
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::create
     * @covers Guzzle\Http\Message\RequestFactory::put
     */
    public function testCreatesNewPutRequestWithBody()
    {
        $request = RequestFactory::put('http://www.google.com/path?q=1&v=2', null, 'Data');
        $this->assertEquals('Data', (string) $request->getBody());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::create
     * @covers Guzzle\Http\Message\RequestFactory::post
     */
    public function testCreatesNewPostRequestWithFields()
    {
        // Use an array
        $request = RequestFactory::create('POST', 'http://www.google.com/path?q=1&v=2', null, array(
            'a' => 'b'
        ));
        $this->assertEquals(array('a' => 'b'), $request->getPostFields()->getAll());
        unset($request);

        // Use a collection
        $request = RequestFactory::create('POST', 'http://www.google.com/path?q=1&v=2', null, new Collection(array(
            'a' => 'b'
        )));
        $this->assertEquals(array('a' => 'b'), $request->getPostFields()->getAll());

        // Use a QueryString
        $request = RequestFactory::create('POST', 'http://www.google.com/path?q=1&v=2', null, new QueryString(array(
            'a' => 'b'
        )));
        $this->assertEquals(array('a' => 'b'), $request->getPostFields()->getAll());

        $request = RequestFactory::post('http://www.test.com/', null, array(
            'a' => 'b',
            'file' => '@' . __FILE__
        ));

        $this->assertEquals(array(
            'a' => 'b',
            'file' => '@' . __FILE__
        ), $request->getPostFields()->getAll());

        $this->assertEquals(array(
            'file' => __FILE__
        ), $request->getPostFiles());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::fromParts
     * @covers Guzzle\Http\Message\RequestFactory::create
     */
    public function testCreatesFromParts()
    {
        $parts = parse_url('http://michael:123@www.google.com:8080/path?q=1&v=2');

        $request = RequestFactory::fromParts('PUT', $parts, null, 'Data');
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('http', $request->getScheme());
        $this->assertEquals('http://www.google.com:8080/path?q=1&v=2', $request->getUrl());
        $this->assertEquals('www.google.com', $request->getHost());
        $this->assertEquals('www.google.com:8080', $request->getHeader('Host'));
        $this->assertEquals('/path', $request->getPath());
        $this->assertEquals('/path?q=1&v=2', $request->getResourceUri());
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $request->getBody());
        $this->assertEquals('Data', (string)$request->getBody());
        $this->assertEquals('michael', $request->getUsername());
        $this->assertEquals('123', $request->getPassword());
        $this->assertEquals('8080', $request->getPort());
        $this->assertEquals(array(
            'scheme' => 'http',
            'host' => 'www.google.com',
            'port' => 8080,
            'path' => '/path',
            'query' => 'q=1&v=2',
        ), parse_url($request->getUrl()));
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::parseMessage
     * @covers Guzzle\Http\Message\RequestFactory::fromMessage
     * @covers Guzzle\Http\Message\RequestFactory::create
     */
    public function testCreatesFromMessage()
    {
        $auth = base64_encode('michael:123');
        $message = "PUT /path?q=1&v=2 HTTP/1.1\r\nHost: www.google.com:8080\r\nContent-Length: 4\r\nAuthorization: Basic {$auth}\r\n\r\nData";
        $request = RequestFactory::fromMessage($message);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('http', $request->getScheme());
        $this->assertEquals('http://www.google.com:8080/path?q=1&v=2', $request->getUrl());
        $this->assertEquals('www.google.com', $request->getHost());
        $this->assertEquals('www.google.com:8080', $request->getHeader('Host'));
        $this->assertEquals('/path', $request->getPath());
        $this->assertEquals('/path?q=1&v=2', $request->getResourceUri());
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $request->getBody());
        $this->assertEquals('Data', (string)$request->getBody());
        $this->assertEquals('michael', $request->getUsername());
        $this->assertEquals('123', $request->getPassword());
        $this->assertEquals('8080', $request->getPort());

        // Test passing a blank message returns false
        $this->assertFalse($request = RequestFactory::fromMessage(''));

        // Test passing a url with no port
        $message = "PUT /path?q=1&v=2 HTTP/1.1\r\nHost: www.google.com\r\nContent-Length: 4\r\nAuthorization: Basic {$auth}\r\n\r\nData";
        $request = RequestFactory::fromMessage($message);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\EntityEnclosingRequest', $request);
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('http', $request->getScheme());
        $this->assertEquals('http://www.google.com/path?q=1&v=2', $request->getUrl());
        $this->assertEquals('www.google.com', $request->getHost());
        $this->assertEquals('/path', $request->getPath());
        $this->assertEquals('/path?q=1&v=2', $request->getResourceUri());
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $request->getBody());
        $this->assertEquals('Data', (string)$request->getBody());
        $this->assertEquals('michael', $request->getUsername());
        $this->assertEquals('123', $request->getPassword());
        $this->assertEquals(80, $request->getPort());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::create
     */
    public function testCreatesNewTraceRequest()
    {
        $request = RequestFactory::create('TRACE', 'http://www.google.com/');
        $this->assertFalse($request instanceof \Guzzle\Http\Message\EntityEnclosingRequest);
        $this->assertEquals('TRACE', $request->getMethod());
    }

    /**
     * @covers Guzzle\Http\Message\RequestFactory::parseMessage
     */
    public function testParsesMessages()
    {
        $parts = RequestFactory::parseMessage(
            "get /testing?q=10&f=3 http/1.1\r\n" .
            "host: localhost:443\n" .
            "authorization: basic bWljaGFlbDoxMjM=\r\n\r\n"
        );

        $this->assertEquals('GET', $parts['method']);
        $this->assertEquals('HTTP', $parts['protocol']);
        $this->assertEquals('1.1', $parts['protocol_version']);
        $this->assertEquals('https', $parts['parts']['scheme']);
        $this->assertEquals('localhost', $parts['parts']['host']);
        $this->assertEquals('443', $parts['parts']['port']);
        $this->assertEquals('michael', $parts['parts']['user']);
        $this->assertEquals('123', $parts['parts']['pass']);
        $this->assertEquals('/testing', $parts['parts']['path']);
        $this->assertEquals('?q=10&f=3', $parts['parts']['query']);
        $this->assertEquals(array(
            'Host' => 'localhost:443',
            'Authorization' => 'basic bWljaGFlbDoxMjM='
        ), $parts['headers']);
        $this->assertEquals('', $parts['body']);
        
        $parts = RequestFactory::parseMessage(
            "get / spydy/1.0\r\n"
        );
        $this->assertEquals('GET', $parts['method']);
        $this->assertEquals('SPYDY', $parts['protocol']);
        $this->assertEquals('1.0', $parts['protocol_version']);
        $this->assertEquals('http', $parts['parts']['scheme']);
        $this->assertEquals('', $parts['parts']['host']);
        $this->assertEquals('', $parts['parts']['port']);
        $this->assertEquals('', $parts['parts']['user']);
        $this->assertEquals('', $parts['parts']['pass']);
        $this->assertEquals('/', $parts['parts']['path']);
        $this->assertEquals('', $parts['parts']['query']);
        $this->assertEquals(array(), $parts['headers']);
        $this->assertEquals('', $parts['body']);
    }
}