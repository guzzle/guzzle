<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\HttpException;
use Guzzle\Http\Url;
use Guzzle\Http\Curl\CurlException;
use Guzzle\Http\Plugin\ExponentialBackoffPlugin;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestException;
use Guzzle\Http\Message\BadResponseException;
use Guzzle\Tests\Common\Mock\MockObserver;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class RequestTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->request = new Request('GET', 'http://www.google.com/');
    }

    public function tearDown()
    {
        unset($this->request);
    }

    /**
     * @covers Guzzle\Http\Message\Request::__construct
     */
    public function testConstructorBuildsRequest()
    {
        // Test passing an array of headers
        $request = new Request('GET', 'http://www.guzzle-project.com/', array(
            'foo' => 'bar'
        ));

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://www.guzzle-project.com/', $request->getUrl());
        $this->assertEquals('bar', $request->getHeader('foo'));

        // Test passing a Collection of headers
        $request = new Request('GET', 'http://www.guzzle-project.com/', new Collection(array(
            'foo' => 'bar'
        )));
        $this->assertEquals('bar', $request->getHeader('foo'));

        // Test passing no headers
        $request = new Request('GET', 'http://www.guzzle-project.com/', null);
        $this->assertFalse($request->hasHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::__toString
     * @covers Guzzle\Http\Message\Request::getRawHeaders
     */
    public function testRequestsCanBeConvertedToRawMessageStrings()
    {
        $auth = base64_encode('michael:123');
        $message = "PUT /path?q=1&v=2 HTTP/1.1\r\nAuthorization: Basic {$auth}\r\nUser-Agent: " . Guzzle::getDefaultUserAgent() . "\r\nHost: www.google.com\r\nContent-Length: 4\r\nExpect: 100-Continue\r\n\r\nData";
        $request = RequestFactory::put('http://www.google.com/path?q=1&v=2', array(
            'Authorization' => 'Basic ' . $auth
        ), 'Data');

        $this->assertEquals($message, $request->__toString());

        // Add authorization after the fact and see that it was put in the message
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = RequestFactory::put($this->getServer()->getUrl(), null, 'Data');
        $request->setAuth('michael', '123', CURLAUTH_BASIC);
        $request->send();
        $str = (string) $request;
        $this->assertTrue((bool) strpos($str, 'Authorization: Basic ' . $auth));
    }

    /**
     * @covers Guzzle\Http\Message\Request::getEventManager
     */
    public function testGetEventManager()
    {
        $mediator = $this->request->getEventManager();
        $this->assertInstanceOf('Guzzle\\Common\\Event\\EventManager', $mediator);
        $this->assertEquals($mediator, $this->request->getEventManager());
        $this->assertEquals($this->request, $mediator->getSubject());
    }

    /**
     * @covers Guzzle\Http\Message\Request::send
     * @covers Guzzle\Http\Message\Request::getResponse
     * @covers Guzzle\Http\Message\Request::setResponse
     * @covers Guzzle\Http\Message\Request::processResponse
     * @covers Guzzle\Http\Message\Request::getResponseBody
     */
    public function testSend()
    {
        $response = new Response(200, array(
            'Content-Length' => 3
        ), 'abc');
        $this->request->setResponse($response, true);
        $r = $this->request->send();
        
        $this->assertSame($response, $r);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $this->request->getResponse());
        $this->assertSame($r, $this->request->getResponse());
        $this->assertEquals('complete', $this->request->getState());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getResponse
     * @covers Guzzle\Http\Message\Request::setResponse
     * @covers Guzzle\Http\Message\Request::processResponse
     * @covers Guzzle\Http\Message\Request::getResponseBody
     */
    public function testGetResponse()
    {
        $this->assertNull($this->request->getResponse());
        
        $response = new Response(200, array(
            'Content-Length' => 3
        ), 'abc');

        $this->request->setResponse($response);
        $this->assertEquals($response, $this->request->getResponse());

        $request = new Request('GET', 'http://www.google.com/');
        $request->setResponse($response, true);
        $request->setState(RequestInterface::STATE_COMPLETE);
        $requestResponse = $request->getResponse();
        $this->assertEquals($response, $requestResponse);
        // Try again, making sure it's still the same response
        $this->assertEquals($requestResponse, $request->getResponse());

        $response = new Response(204);
        $request = new Request('GET', 'http://www.google.com/');
        $request->setResponse($response, true);
        $request->setState('complete');
        $requestResponse = $request->getResponse();
        $this->assertEquals($response, $requestResponse);
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $response->getBody());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getResponse
     * @covers Guzzle\Http\Message\Request::processResponse
     * @covers Guzzle\Http\Message\Request::getResponseBody
     */
    public function testRequestThrowsExceptionOnBadResponse()
    {
        $response = new Response(404, array(
            'Content-Length' => 3
        ), 'abc');

        $request = new Request('GET', 'http://www.google.com/');
        try {
            $request->setResponse($response, true);
            $request->send();
            $this->fail('Expected exception not thrown');
        } catch (BadResponseException $e) {
            $this->assertInstanceOf('Guzzle\\Http\\Message\\RequestInterface', $e->getRequest());
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $e->getResponse());
            $message = "Unsuccessful response | [status code] 404 | "
                . "[reason phrase] Not Found | [url] http://www.google.com/ | "
                . "[request] GET / HTTP/1.1\r\nUser-Agent: " 
                . Guzzle::getDefaultUserAgent() . "\r\nHost: www.google.com\r\n"
                . "\r\n | [response] HTTP/1.1 404 Not Found\r\nContent-Length: 3"
                . "\r\n\r\nabc";

            $this->assertEquals($message, $e->getMessage());
        }
    }

    /**
     * @covers Guzzle\Http\Message\Request::getQuery
     */
    public function testManagesQuery()
    {
        $this->assertInstanceOf('Guzzle\\Http\\QueryString', $this->request->getQuery());
        $this->request->getQuery()->set('test', '123');
        $this->assertEquals('?test=123', $this->request->getQuery(true));
    }

    /**
     * @covers Guzzle\Http\Message\Request::getMethod
     */
    public function testRequestHasMethod()
    {
        $this->assertEquals('GET', $this->request->getMethod());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getScheme
     * @covers Guzzle\Http\Message\Request::setScheme
     */
    public function testRequestHasScheme()
    {
        $this->assertEquals('http', $this->request->getScheme());
        $this->assertEquals($this->request, $this->request->setScheme('https'));
        $this->assertEquals('https', $this->request->getScheme());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getHost
     * @covers Guzzle\Http\Message\Request::setHost
     */
    public function testRequestHasHost()
    {
        $this->assertEquals('www.google.com', $this->request->getHost());
        $this->assertEquals('www.google.com', $this->request->getHeader('Host'));
        
        $this->assertSame($this->request, $this->request->setHost('www2.google.com'));
        $this->assertEquals('www2.google.com', $this->request->getHost());
        $this->assertEquals('www2.google.com', $this->request->getHeader('Host'));

        $this->assertSame($this->request, $this->request->setHost('www.test.com:8081'));
        $this->assertEquals('www.test.com', $this->request->getHost());
        $this->assertEquals(8081, $this->request->getPort());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getProtocolVersion
     * @covers Guzzle\Http\Message\Request::setProtocolVersion
     */
    public function testRequestHasProtocol()
    {
        $this->assertEquals('1.1', $this->request->getProtocolVersion());
        $this->assertEquals($this->request, $this->request->setProtocolVersion('1.1'));
        $this->assertEquals('1.1', $this->request->getProtocolVersion());
        $this->assertEquals($this->request, $this->request->setProtocolVersion('1.0'));
        $this->assertEquals('1.0', $this->request->getProtocolVersion());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getPath
     * @covers Guzzle\Http\Message\Request::setPath
     */
    public function testRequestHasPath()
    {
        $this->assertEquals('/', $this->request->getPath());
        $this->assertEquals($this->request, $this->request->setPath('/index.html'));
        $this->assertEquals('/index.html', $this->request->getPath());
        $this->assertEquals($this->request, $this->request->setPath('index.html'));
        $this->assertEquals('/index.html', $this->request->getPath());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getPort
     * @covers Guzzle\Http\Message\Request::setPort
     */
    public function testRequestHasPort()
    {
        $this->assertEquals(80, $this->request->getPort());
        $this->assertEquals($this->request, $this->request->setPort('8080'));
        $this->assertEquals('8080', $this->request->getPort());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getUsername
     * @covers Guzzle\Http\Message\Request::getPassword
     * @covers Guzzle\Http\Message\Request::setAuth
     */
    public function testRequestHandlesAuthorization()
    {
        // Uninitialized auth
        $this->assertEquals(null, $this->request->getUsername());
        $this->assertEquals(null, $this->request->getPassword());

        // Set an auth
        $this->assertSame($this->request, $this->request->setAuth('michael', '123'));
        $this->assertEquals('michael', $this->request->getUsername());
        $this->assertEquals('123', $this->request->getPassword());

        // Remove the auth
        $this->request->setAuth(false);
        $this->assertEquals(null, $this->request->getUsername());
        $this->assertEquals(null, $this->request->getPassword());

        // Make sure that the cURL based auth works too
        $request = new Request('GET', $this->getServer()->getUrl());
        $request->setAuth('michael', 'password', CURLAUTH_DIGEST);
        $this->assertEquals('michael:password', $request->getCurlOptions()->get(CURLOPT_USERPWD));
        $this->assertEquals(CURLAUTH_DIGEST, $request->getCurlOptions()->get(CURLOPT_HTTPAUTH));
    }

    /**
     * @covers Guzzle\Http\Message\Request::getResourceUri
     */
    public function testGetResourceUri()
    {
        $this->assertEquals('/', $this->request->getResourceUri());
        $this->request->setPath('/index.html');
        $this->assertEquals('/index.html', $this->request->getResourceUri());
        $this->request->getQuery()->add('v', '1');
        $this->assertEquals('/index.html?v=1', $this->request->getResourceUri());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getUrl
     * @covers Guzzle\Http\Message\Request::setUrl
     */
    public function testRequestHasMutableUrl()
    {
        $url = 'http://www.test.com:8081/path?q=123#fragment';
        $u = Url::factory($url);
        $this->assertSame($this->request, $this->request->setUrl($url));
        $this->assertEquals($url, $this->request->getUrl());

        $this->assertSame($this->request, $this->request->setUrl($u));
        $this->assertEquals($url, $this->request->getUrl());

        try {
            $this->request->setUrl(10);
            $this->fail('Expected exception not thrown');
        } catch (\InvalidArgumentException $e) {
        }
    }

    /**
     * @covers Guzzle\Http\Message\Request::setState
     * @covers Guzzle\Http\Message\Request::getState
     */
    public function testRequestHasState()
    {
        $this->assertEquals(RequestInterface::STATE_NEW, $this->request->getState());
        $this->request->setState(RequestInterface::STATE_TRANSFER);
        $this->assertEquals(RequestInterface::STATE_TRANSFER, $this->request->getState());
    }

    /**
     * @covers Guzzle\Http\Message\Request::setResponse
     * @covers Guzzle\Http\Message\Request::getResponse
     * @covers Guzzle\Http\Message\Request::getState
     */
    public function testSetManualResponse()
    {
        $response = new Response(200, array(
            'Date' => 'Sat, 16 Oct 2010 17:27:14 GMT',
            'Expires' => '-1',
            'Cache-Control' => 'private, max-age=0',
            'Content-Type' => 'text/html; charset=ISO-8859-1',
        ), 'response body');

        $this->assertSame($this->request, $this->request->setResponse($response), '-> setResponse() must use a fluent interface');
        $this->assertEquals('complete', $this->request->getState(), '-> setResponse() must change the state of the request to complete');
        $this->assertSame($response, $this->request->getResponse(), '-> setResponse() must set the exact same response that was passed in to it');
    }

    /**
     * @covers Guzzle\Http\Message\Request::setResponseBody
     */
    public function testRequestCanHaveManuallySetResponseBody()
    {
        $file = __DIR__ . '/../../TestData/temp.out';
        if (file_exists($file)) {
            unlink($file);
        }

        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
        $request = RequestFactory::get($this->getServer()->getUrl());
        $request->setResponseBody(EntityBody::factory(fopen($file, 'w+')));
        $request->send();
        
        $this->assertTrue(file_exists($file));
        unlink($file);

        $this->assertEquals('data', $request->getResponse()->getBody(true));
    }

    /**
     * @covers Guzzle\Http\Message\Request::isResponseBodyRepeatable
     */
    public function testDeterminesIfResponseBodyRepeatable()
    {
        // The default stream created for responses is seekable
        $request = RequestFactory::get('http://localhost:' . $this->getServer()->getPort());
        $this->assertTrue($request->isResponseBodyRepeatable());

        // This should return false because an HTTP stream is not seekable
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
        $request->setResponseBody(EntityBody::factory(fopen($this->getServer()->getUrl(), true)));
        $this->assertFalse($request->isResponseBodyRepeatable());
    }

    /**
     * @covers Guzzle\Http\Message\Request::canCache
     */
    public function testDeterminesIfCanCacheRequest()
    {
        $this->assertTrue(RequestFactory::fromMessage(
            "GET / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120\r\n\r\n"
        )->canCache());

        $this->assertTrue(RequestFactory::fromMessage(
            "HEAD / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120\r\n\r\n"
        )->canCache());

        $this->assertFalse(RequestFactory::fromMessage(
            "HEAD / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120, no-store\r\n\r\n"
        )->canCache());

        $this->assertFalse(RequestFactory::fromMessage(
            "POST / HTTP/1.1\r\nHost: www.test.com\r\n\r\n"
        )->canCache());

        $this->assertFalse(RequestFactory::fromMessage(
            "PUT / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120\r\n\r\n"
        )->canCache());
    }

    /**
     * @covers Guzzle\Http\Message\Request
     */
    public function testHoldsCookies()
    {
        $cookie = $this->request->getCookie();
        $this->assertInstanceOf('Guzzle\\Http\\Cookie', $this->request->getCookie());

        // Ensure that the cookie will not affect the request
        $this->assertNull($this->request->getCookie('test'));
        $cookie->set('test', 'abc');
        $this->assertNull($this->request->getCookie('test'));

        // Set a cookie
        $this->assertSame($this->request, $this->request->addCookie('test', 'abc'));
        $this->assertEquals('abc', $this->request->getCookie('test'));

        // Unset the cookies by setting the Cookie header to null
        $this->request->setHeader('Cookie', null);
        $this->assertNull($this->request->getCookie('test'));

        // Set and remove a cookie
        $this->assertSame($this->request, $this->request->addCookie('test', 'abc'));
        $this->assertEquals('abc', $this->request->getCookie('test'));
        $this->assertSame($this->request, $this->request->removeCookie('test'));
        $this->assertNull($this->request->getCookie('test'));
        
        // Remove the cookie header
        $this->assertSame($this->request, $this->request->addCookie('test', 'abc'));
        $this->request->removeHeader('Cookie');
        $this->assertEquals('', (string)$this->request->getCookie());

        // Set the cookie using a cookie object
        $this->assertSame($this->request, $this->request->setCookie($cookie));
        $this->assertEquals($cookie->getAll(), $this->request->getCookie()->getAll());

        // Set the cookie using an array
        $this->assertSame($this->request, $this->request->setCookie(array(
            'test' => 'def'
        )));
        $this->assertEquals(array(
            'test' => 'def'
        ), $this->request->getCookie()->getAll());

        // Test using an invalid value
        try {
            $this->request->setCookie('a');
            $this->fail('Did not throw expected exception when passing invalid value');
        } catch (\InvalidArgumentException $e) {
        }
    }

    /**
     * @covers Guzzle\Http\Message\Request::processResponse
     * @expectedException Guzzle\Http\Message\RequestException
     * @expectedExceptionMessage Unable to set state to complete because no response has been received by the request
     */
    public function testRequestThrowsExceptionWhenSetToCompleteWithNoResponse()
    {
        $this->request->setState(RequestInterface::STATE_COMPLETE);
    }

    /**
     * @covers Guzzle\Http\Message\Request::__clone
     */
    public function testClonedRequestsUseNewInternalState()
    {
        $p = new ExponentialBackoffPlugin();
        $this->request->getEventManager()->attach($p, 100);

        $r = clone $this->request;

        $this->assertEquals(RequestInterface::STATE_NEW, $r->getState());
        $this->assertNotSame($r->getQuery(), $this->request->getQuery());
        $this->assertNotSame($r->getCurlOptions(), $this->request->getCurlOptions());
        $this->assertNotSame($r->getEventManager(), $this->request->getEventManager());
        $this->assertNotSame($r->getHeaders(), $this->request->getHeaders());
        $this->assertNotSame($r->getParams(), $this->request->getParams());
        $this->assertNull($r->getParams()->get('queued_response'));

        $this->assertTrue($this->request->getEventManager()->hasObserver($p));
        $this->assertEquals(100, $r->getEventManager()->getPriority($p));
        $this->assertTrue($r->getEventManager()->hasObserver($p));
    }

    /**
     * @covers Guzzle\Http\Message\Request::changedHeader
     * @covers Guzzle\Http\Message\Request::setHeader
     */
    public function testCatchesAllHostHeaderChanges()
    {
        // Tests setting using headers
        $this->request->setHeader('Host', 'www.abc.com');
        $this->assertEquals('www.abc.com', $this->request->getHost());
        $this->assertEquals('www.abc.com', $this->request->getHeader('Host'));
        $this->assertEquals(80, $this->request->getPort());

        // Tests setting using setHost()
        $this->request->setHost('abc.com');
        $this->assertEquals('abc.com', $this->request->getHost());
        $this->assertEquals('abc.com', $this->request->getHeader('Host'));
        $this->assertEquals(80, $this->request->getPort());

        // Tests setting with a port
        $this->request->setHost('abc.com:8081');
        $this->assertEquals('abc.com', $this->request->getHost());
        $this->assertEquals('abc.com:8081', $this->request->getHeader('Host'));
        $this->assertEquals(8081, $this->request->getPort());

        // Tests setting with a port using the Host header
        $this->request->setHeader('Host', 'solr.com:8983');
        $this->assertEquals('solr.com', $this->request->getHost());
        $this->assertEquals('solr.com:8983', $this->request->getHeader('Host'));
        $this->assertEquals(8983, $this->request->getPort());

        // Tests setting with an inferred 443 port using the Host header
        $this->request->setScheme('https');
        $this->request->setHeader('Host', 'solr.com');
        $this->assertEquals('solr.com', $this->request->getHost());
        $this->assertEquals('solr.com', $this->request->getHeader('Host'));
        $this->assertEquals(443, $this->request->getPort());
    }

    /**
     * @covers Guzzle\Http\Message\Request::setUrl
     */
    public function testRecognizesBasicAuthCredentialsInUrls()
    {
        $this->request->setUrl('http://michael:test@test.com/');
        $this->assertEquals('michael', $this->request->getUsername());
        $this->assertEquals('test', $this->request->getPassword());
    }

    /**
     * This test launches a dummy Guzzle\Http\Server\Server object that listens
     * for incoming requests.  The server allows us to test how cURL sends
     * requests and receives responses.  We can validate the request structure
     * and whether or not the response was interpreted correctly.
     *
     * @covers Guzzle\Http\Message\Request
     */
    public function testRequestCanBeSentUsingCurl()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nExpires: Thu, 01 Dec 1994 16:00:00 GMT\r\nConnection: close\r\n\r\ndata",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nExpires: Thu, 01 Dec 1994 16:00:00 GMT\r\nConnection: close\r\n\r\ndata",
            "HTTP/1.1 404 Not Found\r\nContent-Encoding: application/xml\r\nContent-Length: 48\r\n\r\n<error><mesage>File not found</message></error>"
        ));

        $request = RequestFactory::get($this->getServer()->getUrl());
        $response = $request->send();

        $this->assertEquals('data', $response->getBody(true));
        $this->assertEquals(200, (int)$response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(4, $response->getContentLength());
        $this->assertEquals('Thu, 01 Dec 1994 16:00:00 GMT', $response->getExpires());

        // Test that the same handle can be sent twice without setting state to new
        $response2 = $request->send();
        $this->assertNotSame($response, $response2);

        try {
            $request = RequestFactory::get($this->getServer()->getUrl() . 'index.html');
            $response = $request->send();
            $this->fail('Request did not receive a 404 response');
        } catch (BadResponseException $e) {
        }

        $requests = $this->getServer()->getReceivedRequests(true);
        $messages = $this->getServer()->getReceivedRequests(false);
        $port = $this->getServer()->getPort();

        $userAgent = Guzzle::getDefaultUserAgent();

        $this->assertEquals('127.0.0.1:' . $port, $requests[0]->getHeader('Host'));
        $this->assertEquals('127.0.0.1:' . $port, $requests[1]->getHeader('Host'));
        $this->assertEquals('127.0.0.1:' . $port, $requests[2]->getHeader('Host'));

        $this->assertEquals('/', $requests[0]->getPath());
        $this->assertEquals('/', $requests[1]->getPath());
        $this->assertEquals('/index.html', $requests[2]->getPath());

        $parts = explode("\r\n", $messages[0]);
        $this->assertEquals('GET / HTTP/1.1', $parts[0]);
        
        $parts = explode("\r\n", $messages[1]);
        $this->assertEquals('GET / HTTP/1.1', $parts[0]);

        $parts = explode("\r\n", $messages[2]);
        $this->assertEquals('GET /index.html HTTP/1.1', $parts[0]);
    }

    /**
     * @covers Guzzle\Http\Message\Request
     */
    public function testCurlErrorsAreCaught()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        try {
            $request = RequestFactory::get('http://127.0.0.1:9876/');
            $request->getCurlOptions()->set(CURLOPT_FRESH_CONNECT, true);
            $request->getCurlOptions()->set(CURLOPT_TIMEOUT, 0);
            $request->getCurlOptions()->set(CURLOPT_CONNECTTIMEOUT, 1);
            $request->send();
            $this->fail('CurlException not thrown');
        } catch (CurlException $e) {
            $m = $e->getMessage();

            $this->assertContains('[curl] 7:', $m);
            $this->assertContains('[url] http://127.0.0.1:9876/', $m);
            $this->assertContains('[debug] ', $m);
            $this->assertContains('[info] array (', $m);
            $this->assertContains('Connection refused', $m);
        }
    }

    /**
     * @covers Guzzle\Http\Message\Request::onComplete
     */
    public function testThrowsExceptionsWhenUnsuccessfulResponseIsReceivedByDefault()
    {
        $this->getServer()->enqueue("HTTP/1.1 404 Not found\r\nContent-Length: 0\r\n\r\n");

        try {
            $request = RequestFactory::get($this->getServer()->getUrl() . 'index.html');
            $response = $request->send();
            $this->fail('Request did not receive a 404 response');
        } catch (BadResponseException $e) {
            $this->assertContains('Unsuccessful response ', $e->getMessage());
            $this->assertContains('[status code] 404 | [reason phrase] Not found | [url] http://127.0.0.1:8124/index.html | [request] GET /index.html HTTP/1.1', $e->getMessage());
            $this->assertContains('Host: 127.0.0.1:8124', $e->getMessage());
            $this->assertContains(" | [response] HTTP/1.1 404 Not found", $e->getMessage());
        }
    }

    /**
     * @covers Guzzle\Http\Message\Request::setOnComplete
     */
    public function testCanSetCustomOnCompleteHandler()
    {
        $request = RequestFactory::get($this->getServer()->getUrl());
        $out = '';
        $that = $this;
        $request->setOnComplete(function($request, $response, $default) use (&$out, $that) {
            $out .= $request . "\n" . $response . "\n";
            $that->assertInternalType('array', $default);
        })->send();
        $this->assertContains((string) $request, $out);
        $this->assertContains((string) $request->getResponse(), $out);

        try {
            $a = 'abc';
            $request->setOnComplete($a);
            $this->fail('Set an invalid callback for setOnComplete');
        } catch (\InvalidArgumentException $e) {
        }
    }
}