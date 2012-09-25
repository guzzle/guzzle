<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Common\Collection;
use Guzzle\Http\Utils;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Client;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlHandle;

/**
 * @group server
 */
class CurlHandleTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     * @expectedException InvalidArgumentException
     */
    public function testConstructorExpectsCurlResource()
    {
        $h = new CurlHandle(false, array());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testConstructorExpectsProperOptions()
    {
        $h = curl_init($this->getServer()->getUrl());
        try {
            $ha = new CurlHandle($h, false);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
        }

       $ha = new CurlHandle($h, array(
           CURLOPT_URL => $this->getServer()->getUrl()
       ));
       $this->assertEquals($this->getServer()->getUrl(), $ha->getOptions()->get(CURLOPT_URL));

       $ha = new CurlHandle($h, new Collection(array(
           CURLOPT_URL => $this->getServer()->getUrl()
       )));
       $this->assertEquals($this->getServer()->getUrl(), $ha->getOptions()->get(CURLOPT_URL));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::__construct
     * @covers Guzzle\Http\Curl\CurlHandle::getHandle
     * @covers Guzzle\Http\Curl\CurlHandle::getUrl
     * @covers Guzzle\Http\Curl\CurlHandle::getOptions
     */
    public function testConstructorInitializesObject()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => $this->getServer()->getUrl()
        ));
        $this->assertSame($handle, $h->getHandle());
        $this->assertInstanceOf('Guzzle\\Http\\Url', $h->getUrl());
        $this->assertEquals($this->getServer()->getUrl(), (string) $h->getUrl());
        $this->assertEquals($this->getServer()->getUrl(), $h->getOptions()->get(CURLOPT_URL));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getStderr
     */
    public function testStoresStdErr()
    {
        $request = RequestFactory::getInstance()->create('GET', 'http://test.com');
        $request->getCurlOptions()->set('debug', true);
        $h = CurlHandle::factory($request);
        $this->assertEquals($h->getStderr(true), $h->getOptions()->get(CURLOPT_STDERR));
        $this->assertInternalType('resource', $h->getStderr(true));
        $this->assertInternalType('string', $h->getStderr(false));
        $r = $h->getStderr(true);
        fwrite($r, 'test');
        $this->assertEquals('test', $h->getStderr(false));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::setErrorNo
     * @covers Guzzle\Http\Curl\CurlHandle::getErrorNo
     */
    public function testStoresCurlErrorNumber()
    {
        $h = new CurlHandle(curl_init('http://test.com'), array(CURLOPT_URL => 'http://test.com'));
        $this->assertEquals(CURLE_OK, $h->getErrorNo());
        $h->setErrorNo(CURLE_OPERATION_TIMEOUTED);
        $this->assertEquals(CURLE_OPERATION_TIMEOUTED, $h->getErrorNo());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getStderr
     */
    public function testAccountsForMissingStdErr()
    {
        $handle = curl_init('http://www.test.com/');
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => 'http://www.test.com/'
        ));
        $this->assertNull($h->getStderr(false));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::isAvailable
     */
    public function testDeterminesIfResourceIsAvailable()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array());
        $this->assertTrue($h->isAvailable());

        // Mess it up by closing the handle
        curl_close($handle);
        $this->assertFalse($h->isAvailable());

        // Mess it up by unsetting the handle
        $handle = null;
        $this->assertFalse($h->isAvailable());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getError
     * @covers Guzzle\Http\Curl\CurlHandle::getErrorNo
     * @covers Guzzle\Http\Curl\CurlHandle::getInfo
     */
    public function testWrapsErrorsAndInfo()
    {
        if (!defined('CURLOPT_TIMEOUT_MS')) {
            $this->markTestSkipped('Update curl');
        }

        $settings = array(
            CURLOPT_PORT              => 123,
            CURLOPT_CONNECTTIMEOUT_MS => 1,
            CURLOPT_TIMEOUT_MS        => 1
        );

        $handle = curl_init($this->getServer()->getUrl());
        curl_setopt_array($handle, $settings);
        $h = new CurlHandle($handle, $settings);
        @curl_exec($handle);

        $errors = array(
            CURLE_COULDNT_CONNECT      => "couldn't connect to host",
            CURLE_OPERATION_TIMEOUTED  => 'timeout was reached',
            CURLE_COULDNT_RESOLVE_HOST => 'connection time-out'
        );

        $this->assertTrue(in_array(strtolower($h->getError()), $errors), $h->getError() . ' was not the error');
        $this->assertTrue($h->getErrorNo() > 0);

        $this->assertEquals($this->getServer()->getUrl(), $h->getInfo(CURLINFO_EFFECTIVE_URL));
        $this->assertInternalType('array', $h->getInfo());

        curl_close($handle);
        $this->assertEquals(null, $h->getInfo('url'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getInfo
     */
    public function testGetInfoWithoutDebugMode()
    {
        $client = new Client($this->getServer()->getUrl());
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->getCurlOptions()->set('debug', false);
        $request->setClient($client);
        $response = $request->send();

        $info = $response->getInfo();
        $this->assertFalse(empty($info));
        $this->assertEquals($this->getServer()->getUrl(), $info['url']);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getOptions
     */
    public function testWrapsCurlOptions()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_AUTOREFERER => true,
            CURLOPT_BUFFERSIZE => 1024
        ));

        $this->assertEquals(true, $h->getOptions()->get(CURLOPT_AUTOREFERER));
        $this->assertEquals(1024, $h->getOptions()->get(CURLOPT_BUFFERSIZE));
    }

    /**
     * Data provider for factory tests
     *
     * @return array
     */
    public function dataProvider()
    {
        $testFile = __DIR__ . '/../../../../../phpunit.xml.dist';
        $postBody = new QueryString(array(
            'file' => '@' . $testFile
        ));

        $qs = new QueryString(array(
            'x' => 'y',
            'z' => 'a'
        ));

        $userAgent = Utils::getDefaultUserAgent();
        $auth = base64_encode('michael:123');
        $testFileSize = filesize($testFile);

        return array(
            // Send a regular GET
            array('GET', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('Host: www.google.com', 'User-Agent: ' . $userAgent),
            )),
            // Test that custom request methods can be used
            array('TRACE', 'http://www.google.com/', null, null, array(
                CURLOPT_CUSTOMREQUEST => 'TRACE'
            )),
            // Send a GET using a port
            array('GET', 'http://127.0.0.1:8080', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_PORT => 8080,
                CURLOPT_HTTPHEADER => array('Host: 127.0.0.1:8080', 'User-Agent: ' . $userAgent),
            )),
            // Send a HEAD request
            array('HEAD', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('Host: www.google.com', 'User-Agent: ' . $userAgent),
                CURLOPT_NOBODY => 1
            )),
            // Send a GET using basic auth
            array('GET', 'https://michael:123@localhost/index.html?q=2', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array(
                    'Host: localhost',
                    'Authorization: Basic ' . $auth,
                    'User-Agent: ' . $userAgent
                ),
                CURLOPT_PORT => 443
            )),
            // Send a GET request with custom headers
            array('GET', 'http://localhost:8124/', array(
                'x-test-data' => 'Guzzle'
            ), null, array(
                CURLOPT_PORT => 8124,
                CURLOPT_HTTPHEADER => array(
                    'Host: localhost:8124',
                    'x-test-data: Guzzle',
                    'User-Agent: ' . $userAgent
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'x-test-data'      => 'Guzzle'
            )),
            // Send a POST using a query string
            array('POST', 'http://localhost:8124/post.php', null, $qs, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_POSTFIELDS => 'x=y&z=a',
                CURLOPT_HTTPHEADER => array (
                    'Expect:',
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Content-Type: application/x-www-form-urlencoded'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '7',
                '!Expect'          => null,
                'Content-Type'     => 'application/x-www-form-urlencoded',
                '!Transfer-Encoding' => null
            )),
            // Send a PUT using raw data
            array('PUT', 'http://localhost:8124/put.php', null, EntityBody::factory(fopen($testFile, 'r+')), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_READFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_INFILESIZE => filesize($testFile),
                CURLOPT_HTTPHEADER => array (
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Expect'           => '100-Continue',
                'Content-Length'   => $testFileSize,
                '!Transfer-Encoding' => null
            )),
            // Send a POST request using an array of fields
            array('POST', 'http://localhost:8124/post.php', null, array(
                'x' => 'y',
                'a' => 'b'
            ), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'x=y&a=b',
                CURLOPT_HTTPHEADER => array (
                    'Expect:',
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Content-Type: application/x-www-form-urlencoded'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '7',
                '!Expect'          => null,
                'Content-Type'     => 'application/x-www-form-urlencoded',
                '!Transfer-Encoding' => null
            )),
            // Send a POST request using a POST file
            array('POST', 'http://localhost:8124/post.php', null, $postBody, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => array(
                    'file' => '@' . $testFile . ';type=application/xml;filename=phpunit.xml.dist'
                ),
                CURLOPT_HTTPHEADER => array (
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: multipart/form-data'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '*',
                'Expect'           => '100-Continue',
                'Content-Type'     => 'multipart/form-data; boundary=*',
                '!Transfer-Encoding' => null
            )),
            // Send a POST request with raw POST data and a custom content-type
            array('POST', 'http://localhost:8124/post.php', array(
                'Content-Type' => 'application/json'
            ), '{"hi":"there"}', array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_HTTPHEADER => array (
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: application/json',
                    'Content-Length: 14'
                ),
            ), array(
                '_Accept-Encoding' => '*',
                '_Accept'          => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Type'     => 'application/json',
                'Expect'           => '100-Continue',
                'Content-Length'   => '14',
                '!Transfer-Encoding' => null
            )),
            // Send a POST request with raw POST data, a custom content-type, and use chunked encoding
            array('POST', 'http://localhost:8124/post.php', array(
                'Content-Type'      => 'application/json',
                'Transfer-Encoding' => 'chunked'
            ), '{"hi":"there"}', array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_HTTPHEADER => array (
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: application/json',
                    'Transfer-Encoding: chunked'
                ),
            ), array(
                '_Accept-Encoding' => '*',
                '_Accept'          => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Type'     => 'application/json',
                'Expect'           => '100-Continue',
                'Transfer-Encoding' => 'chunked',
                '!Content-Length'  => ''
            )),
            // Send a POST request with no body
            array('POST', 'http://localhost:8124/post.php', null, '', array(
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => array (
                    'Expect:',
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '0',
                '!Transfer-Encoding' => null
            )),
            // Send a POST request with empty post fields
            array('POST', 'http://localhost:8124/post.php', null, array(), array(
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => array (
                    'Expect:',
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '0',
                '!Transfer-Encoding' => null
            )),
            // Send a PATCH request
            array('PATCH', 'http://localhost:8124/patch.php', null, 'body', array(
                CURLOPT_INFILESIZE => 4,
                CURLOPT_HTTPHEADER => array (
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue'
                )
            )),
            // Send a DELETE request with a body
            array('DELETE', 'http://localhost:8124/delete.php', null, 'body', array(
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_INFILESIZE => 4,
                CURLOPT_HTTPHEADER => array (
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '4',
                'Expect'           => '100-Continue',
                '!Transfer-Encoding' => null
            )),
        );
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::factory
     * @covers Guzzle\Http\Curl\CurlHandle::updateRequestFromTransfer
     * @covers Guzzle\Http\Curl\RequestMediator
     * @dataProvider dataProvider
     */
    public function testFactoryCreatesCurlBasedOnRequest($method, $url, $headers, $body, $options, $expectedHeaders = null)
    {
        $request = RequestFactory::getInstance()->create($method, $url, $headers, $body);
        $request->getCurlOptions()->set('debug', true);

        $originalRequest = clone $request;
        $curlTest = clone $request;
        $handle = CurlHandle::factory($curlTest);

        $this->assertInstanceOf('Guzzle\\Http\\Curl\\CurlHandle', $handle);
        $o = $curlTest->getParams()->get('curl.last_options');

        // Headers are case-insensitive
        if (isset($o[CURLOPT_HTTPHEADER])) {
            $o[CURLOPT_HTTPHEADER] = array_map('strtolower', $o[CURLOPT_HTTPHEADER]);
        }
        if (isset($options[CURLOPT_HTTPHEADER])) {
            $options[CURLOPT_HTTPHEADER] = array_map('strtolower', $options[CURLOPT_HTTPHEADER]);
        }

        $check = 0;
        foreach ($options as $key => $value) {
            $check++;
            $this->assertArrayHasKey($key, $o, '-> Check number ' . $check);
            if ($key != CURLOPT_HTTPHEADER && $key != CURLOPT_POSTFIELDS && (is_array($o[$key])) || $o[$key] instanceof \Closure) {
                $this->assertEquals('callback', $value, '-> Check number ' . $check);
            } else {
                $this->assertTrue($value == $o[$key], '-> Check number ' . $check . ' - ' . var_export($value, true) . ' != ' . var_export($o[$key], true));
            }
        }

        // If we are testing the actual sent headers
        if ($expectedHeaders) {

            // Send the request to the test server
            $client = new Client($this->getServer()->getUrl());
            $request->setClient($client);
            $this->getServer()->flush();
            $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
            $request->send();

            // Get the request that was sent and create a request that we expected
            $requests = $this->getServer()->getReceivedRequests(true);
            $this->assertEquals($method, $requests[0]->getMethod());

            $test = $this->compareHeaders($expectedHeaders, $requests[0]->getHeaders());
            $this->assertFalse($test, $test . "\nSent: \n" . $request . "\n\n" . $requests[0]);

            // Ensure only one Content-Length header is sent
            if ($request->getHeader('Content-Length')) {
                $this->assertEquals((string) $request->getHeader('Content-Length'), (string) $requests[0]->getHeader('Content-Length'));
            }
        }
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testFactoryUsesSpecifiedProtocol()
    {
        $request = RequestFactory::getInstance()->create('GET', 'http://localhost:8124/');
        $request->setProtocolVersion('1.1');
        $handle = CurlHandle::factory($request);
        $options = $request->getParams()->get('curl.last_options');
        $this->assertEquals(CURL_HTTP_VERSION_1_1, $options[CURLOPT_HTTP_VERSION]);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testUploadsPutData()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");

        $client = new Client($this->getServer()->getUrl());
        $request = $client->put('/');
        $request->getCurlOptions()->set('debug', true);
        $request->setBody(EntityBody::factory('test'), 'text/plain', false);
        $request->getCurlOptions()->set('progress', true);

        $o = $this->getWildcardObserver($request);
        $request->send();

        // Make sure that the events were dispatched
        $this->assertTrue($o->has('curl.callback.progress'));

        // Ensure that the request was received exactly as intended
        $r = $this->getServer()->getReceivedRequests(true);

        $this->assertEquals(strtolower($request), strtolower($r[0]));
        $this->assertFalse($r[0]->hasHeader('Transfer-Encoding'));
        $this->assertEquals(4, (string) $r[0]->getHeader('Content-Length'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testUploadsPutDataUsingChunkedEncodingWhenLengthCannotBeDetermined()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi"
        ));
        $client = new Client($this->getServer()->getUrl());
        $request = $client->put('/');
        $request->setBody(EntityBody::factory(fopen($this->getServer()->getUrl(), 'r')), 'text/plain');
        $request->send();

        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('chunked', $r[1]->getHeader('Transfer-Encoding'));
        $this->assertFalse($r[1]->hasHeader('Content-Length'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testUploadsPutDataUsingChunkedEncodingWhenForced()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");

        $client = new Client($this->getServer()->getUrl());
        $request = $client->put('/');
        $request->setBody(EntityBody::factory('hi!'), 'text/plain', true);
        $request->send();

        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('chunked', $r[0]->getHeader('Transfer-Encoding'));
        $this->assertFalse($r[0]->hasHeader('Content-Length'));
        $this->assertEquals('hi!', $r[0]->getBody(true));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testSendsPostRequestsWithFields()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");

        $request = RequestFactory::getInstance()->create('POST', $this->getServer()->getUrl());
        $request->getCurlOptions()->set('debug', true);
        $request->setClient(new Client());
        $request->addPostFields(array(
            'a' => 'b',
            'c' => 'ay! ~This is a test, isn\'t it?'
        ));
        $request->send();

        // Ensure the CURLOPT_POSTFIELDS option was set properly
        $options = $request->getParams()->get('curl.last_options');
        $this->assertEquals('a=b&c=ay%21%20~This%20is%20a%20test%2C%20isn%27t%20it%3F', $options[CURLOPT_POSTFIELDS]);

        // Make sure that the request was sent correctly
        $r = $this->getServer()->getReceivedRequests(true);

        $this->assertEquals(strtolower($request), strtolower($r[0]));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testSendsPostRequestsWithFiles()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");

        $request = RequestFactory::getInstance()->create('POST', $this->getServer()->getUrl());
        $request->getCurlOptions()->set('debug', true);
        $request->setClient(new Client());
        $request->addPostFiles(array(
            'foo' => __FILE__,
        ));
        $request->addPostFields(array(
            'bar' => 'baz',
            'arr' => array('a' => 1, 'b' => 2),
        ));
        $request->send();

        // Ensure the CURLOPT_POSTFIELDS option was set properly
        $options = $request->getParams()->get('curl.last_options');
        $this->assertContains('@' . __FILE__ . ';type=text/x-', $options[CURLOPT_POSTFIELDS]['foo']);
        $this->assertEquals('baz', $options[CURLOPT_POSTFIELDS]['bar']);
        $this->assertEquals('1', $options[CURLOPT_POSTFIELDS]['arr[a]']);
        $this->assertEquals('2', $options[CURLOPT_POSTFIELDS]['arr[b]']);
        // Ensure that a Content-Length header was sent by cURL
        $this->assertTrue($request->hasHeader('Content-Length'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::factory
     */
    public function testHeadersCanBeBlacklisted()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->setClient(new Client('http://www.example.com', array(
            'curl.blacklist' => array('header.Accept', 'header.Foo', CURLOPT_ENCODING)
        )));
        $request->setHeader('Foo', 'Bar');
        $handle = CurlHandle::factory($request);
        $headers = $handle->getOptions()->get(CURLOPT_HTTPHEADER);
        $this->assertTrue(in_array('Accept:', $headers));
        $this->assertTrue(in_array('Foo:', $headers));
        $this->assertFalse($handle->getOptions()->hasKey(CURLOPT_ENCODING));

        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");
        $request->send();

        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertFalse($r[0]->hasHeader('Accept'));
        $this->assertFalse($r[0]->hasHeader('Foo'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::factory
     */
    public function testCurlConfigurationOptionsAreSet()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->setClient(new Client('http://www.example.com'));
        $request->getCurlOptions()->set(CURLOPT_CONNECTTIMEOUT, 99);
        $request->getCurlOptions()->set('curl.fake_opt', 99);
        $request->getCurlOptions()->set(CURLOPT_PORT, 8181);
        $handle = CurlHandle::factory($request);
        $this->assertEquals(99, $handle->getOptions()->get(CURLOPT_CONNECTTIMEOUT));
        $this->assertEquals(8181, $handle->getOptions()->get(CURLOPT_PORT));
        $this->assertNull($handle->getOptions()->get('curl.fake_opt'));
        $this->assertNull($handle->getOptions()->get('fake_opt'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::factory
     */
    public function testAllowsHeadersSetToNull()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->setClient(new Client());
        $request->setBody('test');
        $request->setHeader('Expect', null);
        $request->setHeader('Accept', null);
        $handle = CurlHandle::factory($request);
        $headers = $handle->getOptions()->get(CURLOPT_HTTPHEADER);
        $this->assertTrue(in_array('Expect:', $headers));
        $this->assertTrue(in_array('Accept:', $headers));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::updateRequestFromTransfer
     */
    public function testEnsuresRequestsHaveResponsesWhenUpdatingFromTransfer()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $handle = CurlHandle::factory($request);
        $handle->updateRequestFromTransfer($request);
    }

    /**
     * @return array
     */
    public function redirectWithBodyStringDataProvider()
    {
        return array(
            // Sending POSTs on redirects by setting CURLOPT_POSTREDIR
            array('POST', 'application/x-www-form-urlencoded', array(), 3, 'POST'),
            // Not sending redirected POSTS, but rather GET (omitting the setting or CURLOPT_POSTREDIR)
            array('POST', false, array(), null, 'GET'),
            // Sending PUT on redirect (inherently uses CURLOPT_POSTREDIR because of PUT)
            array('PUT', false, array(), null, 'PUT'),
            // Sending PUT on redirect with a custom Content-Type (inherently uses CURLOPT_POSTREDIR)
            array('PUT', 'foo', array('Content-Type' => 'foo'), null, 'PUT'),
            // Sending PATCH on redirect
            array('PATCH', false, array(), null, 'PATCH'),
            // Sending DELETE on redirect
            array('DELETE', false, array(), null, 'DELETE')
        );
    }

    /**
     * @dataProvider redirectWithBodyStringDataProvider
     */
    public function testCurlFollowsRedirectsUsingStringBody(
        $method,
        $contentType,
        array $headers,
        $followRedirects,
        $resultingMethod
    ) {
        $url = $this->getServer()->getUrl();
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 301 See Other\r\nServer: Apache-Coyote/1.1\r\nLocation: {$url}\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $client = new Client($url);
        $request = $client->createRequest($method, '/test', $headers, '{}');
        $request->getCurlOptions()->set(CurlHandle::BODY_AS_STRING, true);

        if ($followRedirects !== null) {
            $request->getCurlOptions()->set(CURLOPT_POSTREDIR, $followRedirects);
        }
        $request->removeHeader('Expect');
        $request->send();

        // Ensure that 2 requests were sent
        $received = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(2, count($received));

        // Ensure that the resulting request was sent with the expected method
        $this->assertEquals($resultingMethod, $received[1]->getMethod());

        // Ensure the body was sent
        if ($received[1] instanceof EntityEnclosingRequestInterface) {
            $this->assertEquals('{}', (string) $received[1]->getBody());
            $this->assertEquals(2, (int) (string) $received[1]->getHeader('Content-Length'));
        } else {
            $this->assertNull($received[1]->getHeader('Content-Length'));
        }

        // Ensure that a POST content-type was not added for non-posts
        if (!$contentType) {
            $this->assertNull($received[1]->getHeader('Content-Type'));
        } else {
            $this->assertEquals($contentType, (string) $received[1]->getHeader('Content-Type'));
        }
    }

    public function testAllowsWireTransferInfoToBeEnabled()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->getCurlOptions()->set('debug', true);
        $handle = CurlHandle::factory($request);
        $this->assertNotNull($handle->getOptions()->get(CURLOPT_STDERR));
        $this->assertNotNull($handle->getOptions()->get(CURLOPT_VERBOSE));
    }

    public function testAddsCustomCurlOptions()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->getCurlOptions()->set(CURLOPT_TIMEOUT, 200);
        $handle = CurlHandle::factory($request);
        $this->assertEquals(200, $handle->getOptions()->get(CURLOPT_TIMEOUT));
    }

    public function testSendsPostUploadsWithContentDispositionHeaders()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\n\r\nContent-Length: 0\r\n\r\n");

        $fileToUpload = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.json';

        $client = new Client($this->getServer()->getUrl());
        $request = $client->post();
        $request->addPostFile('foo', $fileToUpload, 'application/json');
        $request->addPostFile('foo', __FILE__);

        $request->send();
        $requests = $this->getServer()->getReceivedRequests(true);
        $body = (string) $requests[0]->getBody();

        $this->assertContains('Content-Disposition: form-data; name="foo[0]"; filename="', $body);
        $this->assertContains('Content-Type: application/json', $body);
        $this->assertContains('Content-Type: text/x-', $body);
        $this->assertContains('Content-Disposition: form-data; name="foo[1]"; filename="', $body);
    }

    public function requestMethodProvider()
    {
        return array(array('POST'), array('PUT'), array('PATCH'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     * @dataProvider requestMethodProvider
     */
    public function testSendsRequestsWithNoBodyUsingContentLengthZero($method)
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $client = new Client($this->getServer()->getUrl());
        $client->createRequest($method)->send();
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertFalse($requests[0]->hasHeader('Transfer-Encoding'));
        $this->assertTrue($requests[0]->hasHeader('Content-Length'));
        $this->assertEquals('0', (string) $requests[0]->getHeader('Content-Length'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::parseCurlConfig
     * @dataProvider provideCurlConfig
     */
    public function testParseCurlConfigConvertsStringKeysToConstantKeys($options, $expected)
    {
        $actual = CurlHandle::parseCurlConfig($options);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Data provider for curl configurations
     *
     * @return array
     */
    public function provideCurlConfig()
    {
        return array(
            // Data Set #0
            array(
                array(
                    'curl.CURLOPT_PORT' => 10,
                    'curl.CURLOPT_TIMEOUT' => 99
                ),
                array(
                    CURLOPT_PORT => 10,
                    CURLOPT_TIMEOUT => 99
                )
            ),

            // Date Set #1
            array(
                array(
                    'curl.debug' => true
                ),
                array(
                    'debug' => true
                )
            )
        );
    }

    /**
     * @return array
     */
    public function postDataProvider()
    {
        return array(
            array(301, false, 'GET'),
            array(302, false, 'GET'),
            array(303, false, 'GET'),
            array(307, false, 'POST'),
            array(301, 1, 'POST'),
            array(302, 2, 'POST')
        );
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::factory
     * @dataProvider postDataProvider
     */
    public function testRedirectsPostWithGet($code, $forcePost = false, $method)
    {
        $this->getServer()->flush();
        $port = $this->getServer()->getPort();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 {$code} Foo\r\nLocation: http://localhost:{$port}\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $client = new Client($this->getServer()->getUrl());
        $request = $client->post('/', null, array('foo' => 'bar'));
        if ($forcePost !== false) {
            $request->getCurlOptions()->set(CURLOPT_POSTREDIR, $forcePost);
        }
        $request->send();
        $received = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(2, count($received));
        $this->assertEquals($method, $received[1]->getMethod());
    }

    public function testSeeksToBeginningOfStreamWhenSending()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $client = new Client($this->getServer()->getUrl());
        $request = $client->put('/', null, 'test');
        $request->send();
        $request->send();

        $received = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(2, count($received));
        $this->assertEquals('test', (string) $received[0]->getBody());
        $this->assertEquals('test', (string) $received[1]->getBody());
    }
}
