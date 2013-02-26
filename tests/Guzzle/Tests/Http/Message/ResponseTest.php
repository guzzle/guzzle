<?php

namespace Guzzle\Tests\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\EntityBody;
use Guzzle\Http\HttpException;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Http\Message\Response;

/**
 * @group server
 */
class ResponseTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Response The response object to test
     */
    protected $response;

    public function setup()
    {
        $this->response = new Response(200, new Collection(array(
            'Accept-Ranges' => 'bytes',
            'Age' => '12',
            'Allow' => 'GET, HEAD',
            'Cache-Control' => 'no-cache',
            'Content-Encoding' => 'gzip',
            'Content-Language' => 'da',
            'Content-Length' => '348',
            'Content-Location' => '/index.htm',
            'Content-Disposition' => 'attachment; filename=fname.ext',
            'Content-MD5' => 'Q2hlY2sgSW50ZWdyaXR5IQ==',
            'Content-Range' => 'bytes 21010-47021/47022',
            'Content-Type' => 'text/html; charset=utf-8',
            'Date' => 'Tue, 15 Nov 1994 08:12:31 GMT',
            'ETag' => '737060cd8c284d8af7ad3082f209582d',
            'Expires' => 'Thu, 01 Dec 1994 16:00:00 GMT',
            'Last-Modified' => 'Tue, 15 Nov 1994 12:45:26 GMT',
            'Location' => 'http://www.w3.org/pub/WWW/People.html',
            'Pragma' => 'no-cache',
            'Proxy-Authenticate' => 'Basic',
            'Retry-After' => '120',
            'Server' => 'Apache/1.3.27 (Unix) (Red-Hat/Linux)',
            'Set-Cookie' => 'UserID=JohnDoe; Max-Age=3600; Version=1',
            'Trailer' => 'Max-Forwards',
            'Transfer-Encoding' => 'chunked',
            'Vary' => '*',
            'Via' => '1.0 fred, 1.1 nowhere.com (Apache/1.1)',
            'Warning' => '199 Miscellaneous warning',
            'WWW-Authenticate' => 'Basic'
        )), 'body');
    }

    /**
     * @covers Guzzle\Http\Message\Response::__construct
     */
    public function testConstructor()
    {
        $params = new Collection();
        $body = EntityBody::factory('');
        $response = new Response(200, $params, $body);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($body, $response->getBody());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals("HTTP/1.1 200 OK\r\n\r\n", $response->getRawHeaders());

        // Make sure Content-Length is set automatically
        $response = new Response(200, $params);
        $this->assertEquals("HTTP/1.1 200 OK\r\n\r\n", $response->getRawHeaders());

        // Pass bodies to the response
        $response = new Response(200, null, 'data');
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $response->getBody());
        $response = new Response(200, null, EntityBody::factory('data'));
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $response->getBody());
        $this->assertEquals('data', $response->getBody(true));
        $response = new Response(200, null, '0');
        $this->assertSame('0', $response->getBody(true), 'getBody(true) should return "0" if response body is "0".');

        // Make sure the proper exception is thrown
        try {
            //$response = new Response(200, null, array('foo' => 'bar'));
            //$this->fail('Response did not throw exception when passing invalid body');
        } catch (HttpException $e) {
        }

        // Ensure custom codes can be set
        $response = new Response(2);
        $this->assertEquals(2, $response->getStatusCode());
        $this->assertEquals('', $response->getReasonPhrase());

        // Make sure the proper exception is thrown when sending invalid headers
        try {
            $response = new Response(200, 'adidas');
            $this->fail('Response did not throw exception when passing invalid $headers');
        } catch (BadResponseException $e) {
        }
    }

    /**
     * @covers Guzzle\Http\Message\Response::__toString
     */
    public function test__toString()
    {
        $response = new Response(200);
        $this->assertEquals("HTTP/1.1 200 OK\r\n\r\n", (string) $response);

        // Add another header
        $response = new Response(200, array(
            'X-Test' => 'Guzzle'
        ));
        $this->assertEquals("HTTP/1.1 200 OK\r\nX-Test: Guzzle\r\n\r\n", (string) $response);

        $response = new Response(200, array(
            'Content-Length' => 4
        ), 'test');
        $this->assertEquals("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest", (string) $response);
    }

    /**
     * @covers Guzzle\Http\Message\Response::fromMessage
     */
    public function testFactory()
    {
        $response = Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(4, $response->getContentLength());
        $this->assertEquals('test', $response->getBody(true));

        // Make sure that automatic Content-Length works
        $response = Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
        $this->assertEquals(4, $response->getContentLength());
        $this->assertEquals('test', $response->getBody(true));
    }

    /**
     * @covers Guzzle\Http\Message\Response::fromMessage
     */
    public function testFactoryCanCreateHeadResponses()
    {
        $response = Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\n");
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(4, $response->getContentLength());
        $this->assertEquals('', $response->getBody(true));
    }

    /**
     * @covers Guzzle\Http\Message\Response::fromMessage
     */
    public function testFactoryRequiresMessage()
    {
        $this->assertFalse(Response::fromMessage(''));
    }

    /**
     * @covers Guzzle\Http\Message\Response::getBody
     * @covers Guzzle\Http\Message\Response::setBody
     */
    public function testGetBody()
    {
        $body = EntityBody::factory('');
        $response = new Response(403, new Collection(), $body);
        $this->assertEquals($body, $response->getBody());
        $response->setBody('foo');
        $this->assertEquals('foo', $response->getBody(true));
    }

    /**
     * @covers Guzzle\Http\Message\Response::getStatusCode
     */
    public function testManagesStatusCode()
    {
        $response = new Response(403);
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getMessage
     */
    public function testGetMessage()
    {
        $response = new Response(200, new Collection(array(
            'Content-Length' => 4
        )), 'body');

        $this->assertEquals("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nbody", $response->getMessage());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getRawHeaders
     */
    public function testGetRawHeaders()
    {
        $response = new Response(200, new Collection(array(
            'Keep-Alive' => 155,
            'User-Agent' => 'Guzzle',
            'Content-Length' => 4
        )), 'body');

        $this->assertEquals("HTTP/1.1 200 OK\r\nKeep-Alive: 155\r\nUser-Agent: Guzzle\r\nContent-Length: 4\r\n\r\n", $response->getRawHeaders());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getRequest
     */
    public function testGetRequest()
    {
        $response = new Response(200, new Collection(), 'body');
        $this->assertNull($response->getRequest());
        $request = new \Guzzle\Http\Message\Request('GET', 'http://www.guzzle-project.com/');
        $response->setRequest($request);
        $this->assertEquals($request, $response->getRequest());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getReasonPhrase
     * @covers Guzzle\Http\Message\Response::setStatus
     */
    public function testHandlesStatusAndStatusCodes()
    {
        $response = new Response(200, new Collection(), 'body');
        $this->assertEquals('OK', $response->getReasonPhrase());

        $this->assertSame($response, $response->setStatus(204));
        $this->assertEquals('No Content', $response->getReasonPhrase());
        $this->assertEquals(204, $response->getStatusCode());

        $this->assertSame($response, $response->setStatus(204, 'Testing!'));
        $this->assertEquals('Testing!', $response->getReasonPhrase());
        $this->assertEquals(204, $response->getStatusCode());

        $response->setStatus(2000);
        $this->assertEquals(2000, $response->getStatusCode());
        $this->assertEquals('', $response->getReasonPhrase());

        $response->setStatus(200, 'Foo');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Foo', $response->getReasonPhrase());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isClientError
     */
    public function testIsClientError()
    {
        $response = new Response(403);
        $this->assertTrue($response->isClientError());
        $response = new Response(200);
        $this->assertFalse($response->isClientError());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isError
     */
    public function testIsError()
    {
        $response = new Response(403);
        $this->assertTrue($response->isError());
        $response = new Response(200);
        $this->assertFalse($response->isError());
        $response = new Response(500);
        $this->assertTrue($response->isError());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isInformational
     */
    public function testIsInformational()
    {
        $response = new Response(100);
        $this->assertTrue($response->isInformational());
        $response = new Response(200);
        $this->assertFalse($response->isInformational());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isRedirect
     */
    public function testIsRedirect()
    {
        $response = new Response(301);
        $this->assertTrue($response->isRedirect());
        $response = new Response(200);
        $this->assertFalse($response->isRedirect());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isServerError
     */
    public function testIsServerError()
    {
        $response = new Response(500);
        $this->assertTrue($response->isServerError());
        $response = new Response(400);
        $this->assertFalse($response->isServerError());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isSuccessful
     */
    public function testIsSuccessful()
    {
        $response = new Response(200);
        $this->assertTrue($response->isSuccessful());
        $response = new Response(403);
        $this->assertFalse($response->isSuccessful());
    }

    /**
     * @covers Guzzle\Http\Message\Response::setRequest
     */
    public function testSetRequest()
    {
        $response = new Response(200);
        $this->assertNull($response->getRequest());
        $r = new \Guzzle\Http\Message\Request('GET', 'http://www.guzzle-project.com/');
        $response->setRequest($r);
        $this->assertEquals($r, $response->getRequest());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getAcceptRanges
     */
    public function testGetAcceptRanges()
    {
        $this->assertEquals('bytes', $this->response->getAcceptRanges());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getAge
     */
    public function testGetAge()
    {
        $this->assertEquals(12, $this->response->getAge(false));
        $this->assertEquals(12, $this->response->getAge(true));

        $this->response->removeHeader('Age');
        $this->response->removeHeader('Date');
        $this->assertNull($this->response->getAge());

        $this->response->setHeader('Date', gmdate(ClientInterface::HTTP_DATE, strtotime('-1 minute')));
        // If the test runs slowly, still pass with a +5 second allowance
        $this->assertTrue($this->response->getAge() - 60 <= 5);
        $this->assertNull($this->response->getAge(true));
    }

    /**
     * @covers Guzzle\Http\Message\Response::getAllow
     */
    public function testGetAllow()
    {
        $this->assertEquals('GET, HEAD', $this->response->getAllow());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getCacheControl
     */
    public function testGetCacheControl()
    {
        $this->assertEquals('no-cache', $this->response->getCacheControl());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentEncoding
     */
    public function testGetContentEncoding()
    {
        $this->assertEquals('gzip', $this->response->getContentEncoding());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentLanguage
     */
    public function testGetContentLanguage()
    {
        $this->assertEquals('da', $this->response->getContentLanguage());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentLength
     */
    public function testGetContentLength()
    {
        $this->assertEquals('348', $this->response->getContentLength());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentLocation
     */
    public function testGetContentLocation()
    {
        $this->assertEquals('/index.htm', $this->response->getContentLocation());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentDisposition
     */
    public function testGetContentDisposition()
    {
        $this->assertEquals('attachment; filename=fname.ext', $this->response->getContentDisposition());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentMd5
     */
    public function testGetContentMd5()
    {
        $this->assertEquals('Q2hlY2sgSW50ZWdyaXR5IQ==', $this->response->getContentMd5());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentRange
     */
    public function testGetContentRange()
    {
        $this->assertEquals('bytes 21010-47021/47022', $this->response->getContentRange());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getContentType
     */
    public function testGetContentType()
    {
        $this->assertEquals('text/html; charset=utf-8', $this->response->getContentType());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getDate
     */
    public function testGetDate()
    {
        $this->assertEquals('Tue, 15 Nov 1994 08:12:31 GMT', $this->response->getDate());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getEtag
     */
    public function testGetEtag()
    {
        $this->assertEquals('737060cd8c284d8af7ad3082f209582d', $this->response->getEtag());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getExpires
     */
    public function testGetExpires()
    {
        $this->assertEquals('Thu, 01 Dec 1994 16:00:00 GMT', $this->response->getExpires());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getLastModified
     */
    public function testGetLastModified()
    {
        $this->assertEquals('Tue, 15 Nov 1994 12:45:26 GMT', $this->response->getLastModified());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getLocation
     */
    public function testGetLocation()
    {
        $this->assertEquals('http://www.w3.org/pub/WWW/People.html', $this->response->getLocation());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getPragma
     */
    public function testGetPragma()
    {
        $this->assertEquals('no-cache', $this->response->getPragma());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getProxyAuthenticate
     */
    public function testGetProxyAuthenticate()
    {
        $this->assertEquals('Basic', $this->response->getProxyAuthenticate());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getRetryAfter
     */
    public function testGetRetryAfter()
    {
        $this->assertEquals('120', $this->response->getRetryAfter());
        $t = time() + 1000;
        $d = $t - time();
        $this->response->setHeader('Retry-After', date('r', $t));
        $this->assertEquals($d, $this->response->getRetryAfter());
        $this->response->removeHeader('Retry-After');
        $this->assertNull($this->response->getRetryAfter());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getServer
     */
    public function testGetServer()
    {
        $this->assertEquals('Apache/1.3.27 (Unix) (Red-Hat/Linux)', $this->response->getServer());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getSetCookie
     */
    public function testGetSetCookie()
    {
        $this->assertEquals('UserID=JohnDoe; Max-Age=3600; Version=1', $this->response->getSetCookie());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getSetCookie
     */
    public function testGetMultipleSetCookie()
    {
        $this->response->addHeader('Set-Cookie', 'UserID=Mike; Max-Age=200');
        $this->assertEquals(array(
            'UserID=JohnDoe; Max-Age=3600; Version=1',
            'UserID=Mike; Max-Age=200',
        ), $this->response->getSetCookie()->toArray());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getSetCookie
     */
    public function testGetSetCookieNormalizesHeaders()
    {
        $this->response->addHeaders(array(
            'Set-Cooke'  => 'boo',
            'set-cookie' => 'foo'
        ));

        $this->assertEquals(array(
            'UserID=JohnDoe; Max-Age=3600; Version=1',
            'foo'
        ), $this->response->getSetCookie()->toArray());

        $this->response->addHeaders(array(
            'set-cookie' => 'fubu'
        ));
        $this->assertEquals(array('UserID=JohnDoe; Max-Age=3600; Version=1', 'foo', 'fubu'), $this->response->getSetCookie()->toArray());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getTrailer
     */
    public function testGetTrailer()
    {
        $this->assertEquals('Max-Forwards', $this->response->getTrailer());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getTransferEncoding
     */
    public function testGetTransferEncoding()
    {
        $this->assertEquals('chunked', $this->response->getTransferEncoding());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getVary
     */
    public function testGetVary()
    {
        $this->assertEquals('*', $this->response->getVary());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getVia
     */
    public function testReturnsViaHeader()
    {
        $this->assertEquals('1.0 fred, 1.1 nowhere.com (Apache/1.1)', $this->response->getVia());
    }
    /**
     * @covers Guzzle\Http\Message\Response::getWarning
     */
    public function testGetWarning()
    {
        $this->assertEquals('199 Miscellaneous warning', $this->response->getWarning());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getWwwAuthenticate
     */
    public function testReturnsWwwAuthenticateHeader()
    {
        $this->assertEquals('Basic', $this->response->getWwwAuthenticate());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getConnection
     */
    public function testReturnsConnectionHeader()
    {
        $this->assertEquals(null, $this->response->getConnection());
        $this->response->setHeader('Connection', 'close');
        $this->assertEquals('close', $this->response->getConnection());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getHeader
     */
    public function testReturnsHeaders()
    {
        $this->assertEquals('Basic', $this->response->getHeader('WWW-Authenticate', null, true));
        $this->assertEquals('chunked', $this->response->getHeader('Transfer-Encoding', null, false));
    }

    /**
     * @covers Guzzle\Http\Message\Response::setInfo
     * @covers Guzzle\Http\Message\Response::getInfo
     */
    public function testHasTransferInfo()
    {
        $stats = array (
            'url' => 'http://www.google.com/',
            'content_type' => 'text/html; charset=ISO-8859-1',
            'http_code' => 200,
            'header_size' => 606,
            'request_size' => 53,
            'filetime' => -1,
            'ssl_verify_result' => 0,
            'redirect_count' => 0,
            'total_time' => 0.093284,
            'namelookup_time' => 0.001349,
            'connect_time' => 0.01635,
            'pretransfer_time' => 0.016358,
            'size_upload' => 0,
            'size_download' => 10330,
            'speed_download' => 110737,
            'speed_upload' => 0,
            'download_content_length' => -1,
            'upload_content_length' => 0,
            'starttransfer_time' => 0.07066,
            'redirect_time' => 0,
        );

        // Uninitialized state
        $this->assertNull($this->response->getInfo('url'));
        $this->assertEquals(array(), $this->response->getInfo());

        // Set the stats
        $this->response->setInfo($stats);
        $this->assertEquals($stats, $this->response->getInfo());
        $this->assertEquals(606, $this->response->getInfo('header_size'));
        $this->assertNull($this->response->getInfo('does_not_exist'));
    }

    /**
     * @return Response
     */
    private function getResponse($code, array $headers = null, EntityBody $body = null)
    {
        return new Response($code, $headers, $body);
    }

    /**
     * @covers Guzzle\Http\Message\Response::canCache
     */
    public function testDeterminesIfItCanBeCached()
    {
        $this->assertTrue($this->getResponse(200)->canCache());
        $this->assertTrue($this->getResponse(410)->canCache());
        $this->assertFalse($this->getResponse(404)->canCache());
        $this->assertTrue($this->getResponse(200, array(
            'Cache-Control' => 'public'
        ))->canCache());

        // This has the no-store directive
        $this->assertFalse($this->getResponse(200, array(
            'Cache-Control' => 'private, no-store'
        ))->canCache());

        // The body cannot be read, so it cannot be cached
        $tmp = tempnam('/tmp', 'not-readable');
        $resource = fopen($tmp, 'w');
        $this->assertFalse($this->getResponse(200, array(
            'Transfer-Encoding' => 'chunked'
        ), EntityBody::factory($resource, 10))->canCache());
        unlink($tmp);

        // The body is 0 length, cannot be read, so it can be cached
        $tmp = tempnam('/tmp', 'not-readable');
        $resource = fopen($tmp, 'w');
        $this->assertTrue($this->getResponse(200, array(array(
            'Content-Length' => 0
        )), EntityBody::factory($resource, 0))->canCache());
        unlink($tmp);
    }

    /**
     * @covers Guzzle\Http\Message\Response::getMaxAge
     */
    public function testDeterminesResponseMaxAge()
    {
        $this->assertEquals(null, $this->getResponse(200)->getMaxAge());

        // Uses the response's s-maxage
        $this->assertEquals(140, $this->getResponse(200, array(
            'Cache-Control' => 's-maxage=140'
        ))->getMaxAge());

        // Uses the response's max-age
        $this->assertEquals(120, $this->getResponse(200, array(
            'Cache-Control' => 'max-age=120'
        ))->getMaxAge());

        // Uses the response's max-age
        $this->assertEquals(120, $this->getResponse(200, array(
            'Cache-Control' => 'max-age=120',
            'Expires' => gmdate(ClientInterface::HTTP_DATE, strtotime('+1 day'))
        ))->getMaxAge());

        // Uses the Expires date
        $this->assertGreaterThanOrEqual(82400, $this->getResponse(200, array(
            'Expires' => gmdate(ClientInterface::HTTP_DATE, strtotime('+1 day'))
        ))->getMaxAge());

        // Uses the Expires date
        $this->assertGreaterThanOrEqual(82400, $this->getResponse(200, array(
            'Expires' => gmdate(ClientInterface::HTTP_DATE, strtotime('+1 day'))
        ))->getMaxAge());
    }

    /**
     * @covers Guzzle\Http\Message\Response::canValidate
     */
    public function testDeterminesIfItCanValidate()
    {
        $response = new Response(200);
        $this->assertFalse($response->canValidate());
        $response->setHeader('ETag', '123');
        $this->assertTrue($response->canValidate());
        $response->removeHeader('ETag');
        $this->assertFalse($response->canValidate());
        $response->setHeader('Last-Modified', '123');
        $this->assertTrue($response->canValidate());
    }

    /**
     * @covers Guzzle\Http\Message\Response::getFreshness
     * @covers Guzzle\Http\Message\Response::isFresh
     */
    public function testCalculatesFreshness()
    {
        $response = new Response(200);
        $this->assertNull($response->isFresh());
        $this->assertNull($response->getFreshness());

        $response->addCacheControlDirective('max-age', 120);
        $response->setHeader('Age', 100);
        $this->assertEquals(20, $response->getFreshness());
        $this->assertTrue($response->isFresh());

        $response->setHeader('Age', 120);
        $this->assertEquals(0, $response->getFreshness());
        $this->assertTrue($response->isFresh());

        $response->setHeader('Age', 150);
        $this->assertEquals(-30, $response->getFreshness());
        $this->assertFalse($response->isFresh());
    }

    /**
     * @covers Guzzle\Http\Message\Response::setProtocol
     * @covers Guzzle\Http\Message\Response::getProtocol
     * @covers Guzzle\Http\Message\Response::getProtocolVersion
     */
    public function testHandlesProtocols()
    {
        $this->assertSame($this->response, $this->response->setProtocol('HTTP', '1.0'));
        $this->assertEquals('HTTP', $this->response->getProtocol());
        $this->assertEquals('1.0', $this->response->getProtocolVersion());
    }

    /**
     * @covers Guzzle\Http\Message\Response::isContentType
     */
    public function testComparesContentType()
    {
        $response = new Response(200, array(
            'Content-Type' => 'text/html; charset=ISO-8859-4'
        ));

        $this->assertTrue($response->isContentType('text/html'));
        $this->assertTrue($response->isContentType('TExT/html'));
        $this->assertTrue($response->isContentType('charset=ISO-8859-4'));
        $this->assertFalse($response->isContentType('application/xml'));
    }

    /**
     * @covers Guzzle\Http\Message\Response::isMethodAllowed
     */
    public function testResponseDeterminesIfMethodIsAllowedBaseOnAllowHeader()
    {
        $response = new Response(200, array(
            'Allow' => 'OPTIONS, POST, deletE,GET'
        ));

        $this->assertTrue($response->isMethodAllowed('get'));
        $this->assertTrue($response->isMethodAllowed('GET'));
        $this->assertTrue($response->isMethodAllowed('options'));
        $this->assertTrue($response->isMethodAllowed('post'));
        $this->assertTrue($response->isMethodAllowed('Delete'));
        $this->assertFalse($response->isMethodAllowed('put'));
        $this->assertFalse($response->isMethodAllowed('PUT'));

        $response = new Response(200);
        $this->assertFalse($response->isMethodAllowed('get'));
    }

    /**
     * @covers Guzzle\Http\Message\Response::getPreviousResponse
     * @covers Guzzle\Http\Message\Response::setPreviousResponse
     */
    public function testHasPreviousResponse()
    {
        $response = new Response(200);
        $previous = new Response(302);
        $response->setPreviousResponse($previous);
        $this->assertSame($previous, $response->getPreviousResponse());
        $this->assertNull($previous->getPreviousResponse());
    }

    /**
     * @covers Guzzle\Http\Message\Response::json
     */
    public function testParsesJsonResponses()
    {
        $response = new Response(200, array(), '{"foo": "bar"}');
        $this->assertEquals(array('foo' => 'bar'), $response->json());
        // Return array when null is a service response
        $response = new Response(200);
        $this->assertEquals(array(), $response->json());
    }

    /**
     * @covers Guzzle\Http\Message\Response::json
     * @expectedException \Guzzle\Common\Exception\RuntimeException
     * @expectedExceptionMessage Unable to parse response body into JSON: 4
     */
    public function testThrowsExceptionWhenFailsToParseJsonResponse()
    {
        $response = new Response(200, array(), '{"foo": "');
        $response->json();
    }

    /**
     * @covers Guzzle\Http\Message\Response::xml
     */
    public function testParsesXmlResponses()
    {
        $response = new Response(200, array(), '<abc><foo>bar</foo></abc>');
        $this->assertEquals('bar', (string) $response->xml()->foo);
        // Always return a SimpleXMLElement from the xml method
        $response = new Response(200);
        $this->assertEmpty((string) $response->xml()->foo);
    }

    /**
     * @covers Guzzle\Http\Message\Response::xml
     * @expectedException \Guzzle\Common\Exception\RuntimeException
     * @expectedExceptionMessage Unable to parse response body into XML: String could not be parsed as XML
     */
    public function testThrowsExceptionWhenFailsToParseXmlResponse()
    {
        $response = new Response(200, array(), '<abc');
        $response->xml();
    }
}
