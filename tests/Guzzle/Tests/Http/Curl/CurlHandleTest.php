<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Common\Collection;
use Guzzle\Common\Event;
use Guzzle\Http\Utils;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Curl\CurlMulti;
use Guzzle\Tests\Mock\MockObserver;

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
        $h = CurlHandle::factory(RequestFactory::getInstance()->create('GET', 'http://test.com'));
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
            CURLOPT_PORT => 123,
            CURLOPT_CONNECTTIMEOUT_MS => 1
        );

        $handle = curl_init($this->getServer()->getUrl());
        curl_setopt_array($handle, $settings);
        $h = new CurlHandle($handle, $settings);
        @curl_exec($handle);

        $errors = array(
            CURLE_COULDNT_CONNECT => "couldn't connect to host",
            CURLE_OPERATION_TIMEOUTED => 'timeout was reached'
        );

        $this->assertTrue(in_array(strtolower($h->getError()), $errors));
        $this->assertTrue($h->getErrorNo() > 0);

        $this->assertEquals($this->getServer()->getUrl(), $h->getInfo(CURLINFO_EFFECTIVE_URL));
        $this->assertInternalType('array', $h->getInfo());

        curl_close($handle);
        $this->assertEquals(null, $h->getInfo('url'));
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
        $testFile = __DIR__ . '/../../../../../phpunit.xml';
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
                    'file' => '@' . $testFile . ';type=application/xml'
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
            // Send a POST that does not have a body defined
            array('POST', 'http://localhost:8124/foo.php', null, null, array(
                CURLOPT_HTTPHEADER => array (
                    'Expect:',
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Content-Length: 0'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '0',
                '!Expect'          => null,
                '!Content-Type'     => '*',
                '!Transfer-Encoding' => null
            )),
            // Send a PUT that does not have a body defined
            array('PUT', 'http://localhost:8124/empty-put.php', null, null, array(
                CURLOPT_HTTPHEADER => array (
                    'Expect:',
                    'Host: localhost:8124',
                    'User-Agent: ' . $userAgent,
                    'Content-Length: 0'
                )
            ), array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '0',
                '!Expect'          => null,
                '!Content-Type'     => null,
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
            )
            /*, array(
                '_Accept'          => '*',
                '_Accept-Encoding' => '*',
                'Host'             => '*',
                'User-Agent'       => '*',
                'Content-Length'   => '4',
                'Expect'           => '100-Continue',
                '!Content-Type'     => null,
                '!Transfer-Encoding' => null
            )*/
            ),
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
        $this->assertEquals(array(
            'foo' => '@' . __FILE__ . ';type=text/x-php',
            'bar' => 'baz',
            'arr[a]' => '1',
            'arr[b]' => '2',
        ), $options[CURLOPT_POSTFIELDS]);

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

    public function testCurlFollowsRedirectsUsingPost()
    {
        $this->markTestIncomplete('This is a bug with PHP: https://bugs.php.net/bug.php?id=47204');

        $url = $this->getServer()->getUrl();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 303 See Other\r\nServer: Apache-Coyote/1.1\r\nLocation: {$url}\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $client = new Client($url);
        $request = $client->post('/', null, '{}');
        $request->removeHeader('Expect');
        $request->send();

        $received = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(2, count($received));
    }

    public function testAllowsWireTransferInfoToBeDisabled()
    {
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl());
        $request->getCurlOptions()->set('disable_wire', true);
        $handle = CurlHandle::factory($request);
        $this->assertNull($handle->getOptions()->get(CURLOPT_STDERR));
        $this->assertNull($handle->getOptions()->get(CURLOPT_VERBOSE));
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
        $this->assertContains('Content-Type: text/x-php', $body);
        $this->assertContains('Content-Disposition: form-data; name="foo[1]"; filename="', $body);
    }
}
