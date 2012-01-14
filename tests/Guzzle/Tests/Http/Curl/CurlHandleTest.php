<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Event;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlHandle;
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
        $h = CurlHandle::factory(RequestFactory::create('GET', 'http://test.com'));
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

        $handle = curl_init($this->getServer()->getUrl());
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, 1);
        $h = new CurlHandle($handle, array(
            CURLOPT_TIMEOUT_MS => 1
        ));
        @curl_exec($handle);

        $this->assertEquals('Timeout was reached', $h->getError());
        $this->assertEquals(CURLE_OPERATION_TIMEOUTED, $h->getErrorNo());
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
        $postBody = new QueryString(array(
            'file' => '@' . __DIR__ . '/../../../../../phpunit.xml'
        ));

        $qs = new QueryString(array(
            'x' => 'y',
            'z' => 'a'
        ));

        $userAgent = Guzzle::getDefaultUserAgent();
        $auth = base64_encode('michael:123');

        return array(
            array('GET', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('Host: www.google.com', 'User-Agent: ' . $userAgent),
            )),
            // Test that custom request methods can be used
            array('TRACE', 'http://www.google.com/', null, null, array(
                CURLOPT_CUSTOMREQUEST => 'TRACE'
            )),
            array('GET', 'http://127.0.0.1:8080', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_PORT => 8080,
                CURLOPT_HTTPHEADER => array('Host: 127.0.0.1:8080', 'User-Agent: ' . $userAgent),
            )),
            array('HEAD', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('Host: www.google.com', 'User-Agent: ' . $userAgent),
                CURLOPT_CUSTOMREQUEST => 'HEAD',
                CURLOPT_NOBODY => 1
            )),
            array('GET', 'https://michael:123@www.guzzle-project.com/index.html?q=2', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array(
                    'Host: www.guzzle-project.com',
                    'Authorization: Basic ' . $auth,
                    'User-Agent: ' . $userAgent
                ),
                CURLOPT_PORT => 443
            )),
            array('GET', 'http://www.guzzle-project.com:8080/', array(
                    'X-Test-Data' => 'Guzzle'
                ), null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('Host: www.guzzle-project.com:8080', 'X-Test-Data: Guzzle', 'User-Agent: ' . $userAgent),
                CURLOPT_PORT => 8080
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, $qs, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POSTFIELDS => 'x=y&z=a',
                CURLOPT_HTTPHEADER => array (
                    'Host: www.guzzle-project.com',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('PUT', 'http://www.guzzle-project.com/put.php', null, EntityBody::factory(fopen(__DIR__ . '/../../../../../phpunit.xml', 'r+')), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_READFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array (
                    'Host: www.guzzle-project.com',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Length: ' . filesize(__DIR__ . '/../../../../../phpunit.xml')
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, array(
                'a' => '2'
            ), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'a=2',
                CURLOPT_HTTPHEADER => array (
                    'Host: www.guzzle-project.com',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, array(
                'x' => 'y'
            ), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'x=y',
                CURLOPT_HTTPHEADER => array (
                    'Host: www.guzzle-project.com',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, $postBody, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => array(
                    'file' => '@' . __DIR__ . '/../../../../../phpunit.xml'
                ),
                CURLOPT_HTTPHEADER => array (
                    'Host: www.guzzle-project.com',
                    'User-Agent: ' . $userAgent,
                    'Expect: 100-Continue',
                    'Content-Type: multipart/form-data'
                )
            )),
        );
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::factory
     * @dataProvider dataProvider
     */
    public function testFactoryCreatesCurlBasedOnRequest($method, $url, $headers, $body, $options)
    {
        $request = RequestFactory::create($method, $url, $headers, $body);
        $handle = CurlHandle::factory($request);
        
        $this->assertInstanceOf('Guzzle\\Http\\Curl\\CurlHandle', $handle);
        $o = $request->getParams()->get('curl.last_options');

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
        
        $request = null;
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testFactoryUsesSpecifiedProtocol()
    {
        $request = RequestFactory::create('GET', 'http://www.guzzle-project.com/');
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

        $request = RequestFactory::create('PUT', $this->getServer()->getUrl());
        $request->setClient(new Client());
        $request->setBody(EntityBody::factory('test'), 'text/plain', false);
        
        $o = $this->getWildcardObserver($request);
        $request->send();

        // Make sure that the events were dispatched
        $this->assertTrue($o->has('curl.callback.read'));
        $this->assertTrue($o->has('curl.callback.write'));
        $this->assertTrue($o->has('curl.callback.progress'));

        // Make sure that the data was sent through the event
        $this->assertEquals('test', $o->getData('curl.callback.read', 'read'));
        $this->assertEquals('hi', $o->getData('curl.callback.write', 'write'));

        // Ensure that the request was received exactly as intended
        $r = $this->getServer()->getReceivedRequests(true);
        
        $this->assertEquals((string) $request, (string) $r[0]);
    }
    
    /**
     * @covers Guzzle\Http\Curl\CurlHandle
     */
    public function testSendsPostRequests()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");
        // Create a new request using the same connection and POST
        $request = RequestFactory::create('POST', $this->getServer()->getUrl());
        $request->setClient(new Client());
        $request->addPostFields(array(
            'a' => 'b',
            'c' => 'ay! ~This is a test, isn\'t it?'
        ));
        $request->send();

        // Make sure that the request was sent correctly
        $r = $this->getServer()->getReceivedRequests(true);

        $this->assertEquals((string) $request, (string) $r[0]);
    }
}