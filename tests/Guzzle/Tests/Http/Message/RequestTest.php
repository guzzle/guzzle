<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Url;
use Guzzle\Http\Client;
use Guzzle\Http\Utils;
use Guzzle\Plugin\Async\AsyncPlugin;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\RedirectPlugin;
use Guzzle\Http\Exception\BadResponseException;

/**
 * @group server
 */
class RequestTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Client
     */
    protected $client;

    protected function setUp()
    {
        $this->client = new Client($this->getServer()->getUrl());
        $this->request = $this->client->get();
    }

    public function tearDown()
    {
        unset($this->request);
        unset($this->client);
    }

    /**
     * @covers Guzzle\Http\Message\Request::__construct
     */
    public function testConstructorBuildsRequestWithArrayHeaders()
    {
        // Test passing an array of headers
        $request = new Request('GET', 'http://www.guzzle-project.com/', array(
            'foo' => 'bar'
        ));

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://www.guzzle-project.com/', $request->getUrl());
        $this->assertEquals('bar', $request->getHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::getAllEvents
     */
    public function testDescribesEvents()
    {
        $this->assertInternalType('array', Request::getAllEvents());
    }

    /**
     * @covers Guzzle\Http\Message\Request::__construct
     */
    public function testConstructorBuildsRequestWithCollectionHeaders()
    {
        $request = new Request('GET', 'http://www.guzzle-project.com/', new Collection(array(
            'foo' => 'bar'
        )));
        $this->assertEquals('bar', $request->getHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::__construct
     */
    public function testConstructorBuildsRequestWithNoHeaders()
    {
        $request = new Request('GET', 'http://www.guzzle-project.com/', null);
        $this->assertFalse($request->hasHeader('foo'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::__construct
     */
    public function testConstructorHandlesBasicAuth()
    {
        $auth = base64_encode('michael:foo');
        $request = new Request('GET', 'http://www.guzzle-project.com/', array(
            'Authorization' => 'Basic ' . $auth
        ));
        $this->assertEquals('michael', $request->getUserName());
        $this->assertEquals('foo', $request->getPassword());
        $this->assertEquals('Basic ' . $auth, (string) $request->getHeader('Authorization'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::__construct
     */
    public function testConstructorHandlesNonBasicAuth()
    {
        $request = new Request('GET', 'http://www.guzzle-project.com/', array(
            'Authorization' => 'Foo bar'
        ));
        $this->assertNull($request->getUserName());
        $this->assertNull($request->getPassword());
        $this->assertEquals('Foo bar', (string) $request->getHeader('Authorization'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::__toString
     * @covers Guzzle\Http\Message\Request::getRawHeaders
     * @covers Guzzle\Http\Message\AbstractMessage::getHeaderLines
     */
    public function testRequestsCanBeConvertedToRawMessageStrings()
    {
        $auth = base64_encode('michael:123');
        $message = "PUT /path?q=1&v=2 HTTP/1.1\r\n"
            . "Host: www.google.com\r\n"
            . "Authorization: Basic {$auth}\r\n"
            . "User-Agent: " . Utils::getDefaultUserAgent() . "\r\n"
            . "Content-Length: 4\r\n\r\nData";

        $request = RequestFactory::getInstance()->create('PUT', 'http://www.google.com/path?q=1&v=2', array(
            'Authorization' => 'Basic ' . $auth
        ), 'Data');

        $this->assertEquals($message, $request->__toString());
    }

    /**
     * Add authorization after the fact and see that it was put in the message
     *
     * @covers Guzzle\Http\Message\Request::__toString
     * @covers Guzzle\Http\Message\Request::getRawHeaders
     */
    public function testRequestStringsIncludeAuth()
    {
        $auth = base64_encode('michael:123');
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl(), null, 'Data')
            ->setClient($this->client)
            ->setAuth('michael', '123', CURLAUTH_BASIC);
        $request->send();

        $this->assertContains('Authorization: Basic ' . $auth, (string) $request);
    }

    /**
     * @covers Guzzle\Http\Message\Request::getEventDispatcher
     */
    public function testGetEventDispatcher()
    {
        $d = $this->request->getEventDispatcher();
        $this->assertInstanceOf('Symfony\\Component\\EventDispatcher\\EventDispatcherInterface', $d);
        $this->assertEquals($d, $this->request->getEventDispatcher());
    }

    /**
     * @covers Guzzle\Http\Message\Request::getClient
     * @covers Guzzle\Http\Message\Request::setClient
     */
    public function testRequestsManageClients()
    {
        $request = new Request('GET', 'http://test.com');
        $this->assertNull($request->getClient());
        $request->setClient($this->client);
        $this->assertSame($this->client, $request->getClient());
    }

    /**
     * @covers Guzzle\Http\Message\Request::send
     * @expectedException \RuntimeException
     * @expectedExceptionMessage A client must be set on the request
     */
    public function testRequestsRequireClients()
    {
        $request = new Request('GET', 'http://test.com');
        $request->send();
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
        $this->assertSame($response, $requestResponse);

        // Try again, making sure it's still the same response
        $this->assertSame($requestResponse, $request->getResponse());

        $response = new Response(204);
        $request = new Request('GET', 'http://www.google.com/');
        $request->setResponse($response, true);
        $request->setState('complete');
        $requestResponse = $request->getResponse();
        $this->assertSame($response, $requestResponse);
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
        $request->setClient($this->client);
        try {
            $request->setResponse($response, true);
            $request->send();
            $this->fail('Expected exception not thrown');
        } catch (BadResponseException $e) {
            $this->assertInstanceOf('Guzzle\\Http\\Message\\RequestInterface', $e->getRequest());
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $e->getResponse());
            $this->assertContains('Client error response', $e->getMessage());
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
        $this->assertEquals('127.0.0.1', $this->request->getHost());
        $this->assertEquals('127.0.0.1:8124', (string) $this->request->getHeader('Host'));

        $this->assertSame($this->request, $this->request->setHost('www2.google.com'));
        $this->assertEquals('www2.google.com', $this->request->getHost());
        $this->assertEquals('www2.google.com:8124', (string) $this->request->getHeader('Host'));

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
        $this->assertEquals(8124, $this->request->getPort());
        $this->assertEquals('127.0.0.1:8124', $this->request->getHeader('Host'));

        $this->assertEquals($this->request, $this->request->setPort('8080'));
        $this->assertEquals('8080', $this->request->getPort());
        $this->assertEquals('127.0.0.1:8080', $this->request->getHeader('Host'));

        $this->request->setPort(80);
        $this->assertEquals('127.0.0.1', $this->request->getHeader('Host'));
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
     * @covers Guzzle\Http\Message\Request::getResource
     */
    public function testGetResourceUri()
    {
        $this->assertEquals('/', $this->request->getResource());
        $this->request->setPath('/index.html');
        $this->assertEquals('/index.html', $this->request->getResource());
        $this->request->getQuery()->add('v', '1');
        $this->assertEquals('/index.html?v=1', $this->request->getResource());
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
        $request = RequestFactory::getInstance()->create('GET', $this->getServer()->getUrl());
        $request->setClient($this->client);
        $entityBody = EntityBody::factory(fopen($file, 'w+'));
        $request->setResponseBody($entityBody);
        $response = $request->send();
        $this->assertSame($entityBody, $response->getBody());

        $this->assertTrue(file_exists($file));
        $this->assertEquals('data', file_get_contents($file));
        unlink($file);

        $this->assertEquals('data', $response->getBody(true));
    }

    /**
     * @covers Guzzle\Http\Message\Request::isResponseBodyRepeatable
     */
    public function testDeterminesIfResponseBodyRepeatable()
    {
        // The default stream created for responses is seekable
        $request = RequestFactory::getInstance()->create('GET', 'http://localhost:' . $this->getServer()->getPort());
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
        $this->assertTrue(RequestFactory::getInstance()->fromMessage(
            "GET / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120\r\n\r\n"
        )->canCache());

        $this->assertTrue(RequestFactory::getInstance()->fromMessage(
            "HEAD / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120\r\n\r\n"
        )->canCache());

        $this->assertFalse(RequestFactory::getInstance()->fromMessage(
            "HEAD / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120, no-store\r\n\r\n"
        )->canCache());

        $this->assertFalse(RequestFactory::getInstance()->fromMessage(
            "POST / HTTP/1.1\r\nHost: www.test.com\r\n\r\n"
        )->canCache());

        $this->assertFalse(RequestFactory::getInstance()->fromMessage(
            "PUT / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, max-age=120\r\n\r\n"
        )->canCache());
    }

    /**
     * @covers Guzzle\Http\Message\Request
     */
    public function testHoldsCookies()
    {
        $this->assertNull($this->request->getCookie('test'));

        // Set a cookie
        $this->assertSame($this->request, $this->request->addCookie('test', 'abc'));
        $this->assertEquals('abc', $this->request->getCookie('test'));

        // Multiple cookies by setting the Cookie header
        $this->request->setHeader('Cookie', '__utma=1.638370270.1344367610.1374365610.1944450276.2; __utmz=1.1346368610.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none); hl=de; PHPSESSID=ak93pqashi5uubuoq8fjv60897');
        $this->assertEquals('1.638370270.1344367610.1374365610.1944450276.2', $this->request->getCookie('__utma'));
        $this->assertEquals('1.1346368610.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none)', $this->request->getCookie('__utmz'));
        $this->assertEquals('de', $this->request->getCookie('hl'));
        $this->assertEquals('ak93pqashi5uubuoq8fjv60897', $this->request->getCookie('PHPSESSID'));

        // Unset the cookies by setting the Cookie header to null
        $this->request->setHeader('Cookie', null);
        $this->assertNull($this->request->getCookie('test'));
        $this->request->removeHeader('Cookie');

        // Set and remove a cookie
        $this->assertSame($this->request, $this->request->addCookie('test', 'abc'));
        $this->assertEquals('abc', $this->request->getCookie('test'));
        $this->assertSame($this->request, $this->request->removeCookie('test'));
        $this->assertNull($this->request->getCookie('test'));

        // Remove the cookie header
        $this->assertSame($this->request, $this->request->addCookie('test', 'abc'));
        $this->request->removeHeader('Cookie');
        $this->assertEquals('', (string) $this->request->getHeader('Cookie'));

        // Remove a cookie value
        $this->request->addCookie('foo', 'bar')->addCookie('baz', 'boo');
        $this->request->removeCookie('foo');
        $this->assertEquals(array(
            'baz' => 'boo'
        ), $this->request->getCookies());

        $this->request->addCookie('foo', 'bar');
        $this->assertEquals('baz=boo; foo=bar', (string) $this->request->getHeader('Cookie'));
    }

    /**
     * @covers Guzzle\Http\Message\Request::processResponse
     * @expectedException Guzzle\Http\Exception\RequestException
     * @expectedExceptionMessage Error completing request
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
        $p = new AsyncPlugin();
        $this->request->getEventDispatcher()->addSubscriber($p);
        $h = $this->request->getHeader('Host');

        $r = clone $this->request;
        $this->assertEquals(RequestInterface::STATE_NEW, $r->getState());
        $this->assertNotSame($r->getQuery(), $this->request->getQuery());
        $this->assertNotSame($r->getCurlOptions(), $this->request->getCurlOptions());
        $this->assertNotSame($r->getEventDispatcher(), $this->request->getEventDispatcher());
        $this->assertEquals($r->getHeaders(), $this->request->getHeaders());
        $this->assertNotSame($h, $r->getHeader('Host'));
        $this->assertNotSame($r->getParams(), $this->request->getParams());
        $this->assertNull($r->getParams()->get('queued_response'));

        $this->assertTrue($this->request->getEventDispatcher()->hasListeners('request.sent'));
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
        $this->assertEquals('www.abc.com:8124', $this->request->getHeader('Host'));
        $this->assertEquals(8124, $this->request->getPort());

        // Tests setting using setHost()
        $this->request->setHost('abc.com');
        $this->assertEquals('abc.com', $this->request->getHost());
        $this->assertEquals('abc.com:8124', $this->request->getHeader('Host'));
        $this->assertEquals(8124, $this->request->getPort());

        // Tests setting with a port
        $this->request->setHost('abc.com:8081');
        $this->assertEquals('abc.com', $this->request->getHost());
        $this->assertEquals('abc.com:8081', $this->request->getHeader('Host'));
        $this->assertEquals(8081, $this->request->getPort());

        // Tests setting with a port using the Host header
        $this->request->setHeader('Host', 'solr.com:8983');
        $this->assertEquals('solr.com', $this->request->getHost());
        $this->assertEquals('solr.com:8983', (string) $this->request->getHeader('Host'));
        $this->assertEquals(8983, $this->request->getPort());

        // Tests setting with an inferred 443 port using the Host header
        $this->request->setScheme('https');
        $this->request->setHeader('Host', 'solr.com');
        $this->assertEquals('solr.com', $this->request->getHost());
        $this->assertEquals('solr.com:8983', (string) $this->request->getHeader('Host'));
        $this->assertEquals(8983, $this->request->getPort());
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

        $request = RequestFactory::getInstance()->create('GET', $this->getServer()->getUrl());
        $request->setClient($this->client);
        $response = $request->send();

        $this->assertEquals('data', $response->getBody(true));
        $this->assertEquals(200, (int) $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(4, $response->getContentLength());
        $this->assertEquals('Thu, 01 Dec 1994 16:00:00 GMT', $response->getExpires());

        // Test that the same handle can be sent twice without setting state to new
        $response2 = $request->send();
        $this->assertNotSame($response, $response2);

        try {
            $request = RequestFactory::getInstance()->create('GET', $this->getServer()->getUrl() . 'index.html');
            $request->setClient($this->client);
            $response = $request->send();
            $this->fail('Request did not receive a 404 response');
        } catch (BadResponseException $e) {
        }

        $requests = $this->getServer()->getReceivedRequests(true);
        $messages = $this->getServer()->getReceivedRequests(false);
        $port = $this->getServer()->getPort();

        $userAgent = Utils::getDefaultUserAgent();

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
     * @covers Guzzle\Http\Message\Request::onRequestError
     */
    public function testThrowsExceptionsWhenUnsuccessfulResponseIsReceivedByDefault()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 404 Not found\r\nContent-Length: 0\r\n\r\n");

        try {
            $request = $this->client->get('/index.html');
            $response = $request->send();
            $this->fail('Request did not receive a 404 response');
        } catch (BadResponseException $e) {
            $this->assertContains('Client error response', $e->getMessage());
            $this->assertContains('[status code] 404', $e->getMessage());
            $this->assertContains('[reason phrase] Not found', $e->getMessage());
        }
    }

    /**
     * @covers Guzzle\Http\Message\Request::onRequestError
     */
    public function testCanShortCircuitErrorHandling()
    {
        $request = $this->request;
        $response = new Response(404);
        $request->setResponse($response, true);
        $out = '';
        $that = $this;
        $request->getEventDispatcher()->addListener('request.error', function($event) use (&$out, $that) {
            $out .= $event['request'] . "\n" . $event['response'] . "\n";
            $event->stopPropagation();
        });
        $request->send();
        $this->assertContains((string) $request, $out);
        $this->assertContains((string) $request->getResponse(), $out);
        $this->assertSame($response, $request->getResponse());
    }

    /**
     * @covers Guzzle\Http\Message\Request::processResponse
     * @covers Guzzle\Http\Message\Request::onRequestError
     */
    public function testCanOverrideUnsuccessfulResponses()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 404 NOT FOUND\r\n" .
            "Content-Length: 0\r\n" .
            "\r\n",
            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n" .
            "\r\n"
        ));

        $newResponse = null;

        $request = $this->request;
        $request->getEventDispatcher()->addListener('request.error', function($event) use (&$newResponse) {
            if ($event['response']->getStatusCode() == 404) {
                $newRequest = clone $event['request'];
                $newResponse = $newRequest->send();
                // Override the original response and bypass additional response processing
                $event['response'] = $newResponse;
                // Call $event['request']->setResponse($newResponse); to re-apply events
                $event->stopPropagation();
            }
        });

        $request->send();

        $this->assertEquals(200, $request->getResponse()->getStatusCode());
        $this->assertSame($newResponse, $request->getResponse());
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests()));
    }

    /**
     * @covers Guzzle\Http\Message\Request
     */
    public function testCanRetrieveUrlObject()
    {
        $request = new Request('GET', 'http://www.example.com/foo?abc=d');
        $this->assertInstanceOf('Guzzle\Http\Url', $request->getUrl(true));
        $this->assertEquals('http://www.example.com/foo?abc=d', $request->getUrl());
        $this->assertEquals('http://www.example.com/foo?abc=d', (string) $request->getUrl(true));
    }

    /**
     * Users sometimes want to use a custom stream when receiving a response body.
     * Because of the various potential for retrying failed requests, the stream
     * specified by the user should only be written to in the event that a
     * successful response was received.  Otherwise, a new temp stream is created
     * to store the body of the failed request.
     *
     * @covers Guzzle\Http\Message\Request::receiveResponseHeader
     */
    public function testReceivingUnsuccessfulResponseUsesOtherResponseBody()
    {
        $request = new Request('GET', $this->getServer()->getUrl());
        $body = EntityBody::factory();
        $request->setResponseBody($body);
        $request->receiveResponseHeader('HTTP/1.1 503 Service Unavailable');
        $this->assertNotSame($body, $request->getResponse()->getBody());
    }

    /**
     * Many RESTful frameworks omit the text status from the header. That
     * provides a response like "HTTP/1.1 200". Prevent an Undefined offset
     * by checking to see how many parts of the status line are provided
     * before trying to assign them.
     *
     * @covers Guzzle\Http\Message\Request::receiveResponseHeader
     */
    public function testReceivingShortStatusLineResponse()
    {
        $request = new Request('GET', $this->getServer()->getUrl());
        $request->receiveResponseHeader('HTTP/1.1 200');
        $this->assertSame(200, $request->getResponse()->getStatusCode());
        $this->assertSame('OK', $request->getResponse()->getReasonPhrase());
    }

    /**
     * @covers Guzzle\Http\Message\Request::receiveResponseHeader
     */
    public function testAddsPreviousResponseToResponseWhenRedirecting()
    {
        $url = $this->getServer()->getUrl();
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 303 SEE OTHER\r\n" .
            "Content-Length: 0\r\n" .
            "Location: {$url}/foo\r\n\r\n",
            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n"
        ));

        $request = $this->request;
        $request->send();
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests()));
        $this->assertEquals(200, $request->getResponse()->getStatusCode());
        $this->assertEquals(303, $request->getResponse()->getPreviousResponse()->getStatusCode());
    }

    public function testUnresolvedRedirectsReturnResponse()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 303 SEE OTHER\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Foo\r\nLocation: /foo\r\nContent-Length: 0\r\n\r\n"
        ));
        $request = $this->request;
        $this->assertEquals(303, $request->send()->getStatusCode());
        $request->getParams()->set(RedirectPlugin::DISABLE, true);
        $this->assertEquals(301, $request->send()->getStatusCode());
    }

    public function testCanSendCustomRequests()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = $this->client->createRequest('PROPFIND', $this->getServer()->getUrl(), array(
            'Content-Type' => 'text/plain'
        ), 'foo');
        $response = $request->send();
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('PROPFIND', $requests[0]->getMethod());
        $this->assertEquals(3, (string) $requests[0]->getHeader('Content-Length'));
        $this->assertEquals('foo', (string) $requests[0]->getBody());
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testEnsuresFileCanBeCreated()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
        $this->client->get('/')->setResponseBody('/wefwefefefefwewefwe/wefwefwefefwe/wefwefewfw.txt')->send();
    }

    public function testAllowsFilenameForDownloadingContent()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
        $name = sys_get_temp_dir() . '/foo.txt';
        $this->client->get('/')->setResponseBody($name)->send();
        $this->assertEquals('test', file_get_contents($name));
        unlink($name);
    }
}
