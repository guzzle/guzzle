<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Guzzle;
use Guzzle\Http\Curl\CurlConstants;
use Guzzle\Http\Curl\CurlFactory;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\QueryString;
use Guzzle\Http\Url;
use Guzzle\Tests\Common\Mock\MockObserver;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public static $dontConvert = array(
        'CURLOPT_MAXREDIRS',
        'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_FILETIME',
        'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST',
        'CURLOPT_RETURNTRANSFER',
        'CURLOPT_HTTPHEADER',
        'CURLOPT_HEADER',
        'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS',
        'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_USERAGENT',
        'CURLOPT_NOPROGRESS',
        'CURLOPT_BUFFERSIZE',
        'CURLOPT_PORT'
    );

    /**
     * Convert cURL option and value integers into a readable array
     *
     * @param array $options
     *
     * @return array
     */
    public static function getReadableCurlOptions(array $options)
    {
        $readable = array();

        foreach ($options as $key => $value) {

            $readableKey = $key;
            $readableValue = $value;

            // Convert the key
            foreach (CurlConstants::getOptions() as $ok => $ov) {
                if ($ov === $key) {
                    $readableKey = $ok;
                    break;
                }
            }

            if (!in_array($readableKey, self::$dontConvert)) {
                foreach (CurlConstants::getValues() as $k => $v) {
                    if ($value == 1 && $readableKey != 'CURLOPT_HTTPAUTH') {
                        $readableValue = true;
                    } else if ($v && $v === $value) {
                        $readableValue = $k;
                        break;
                    } else if (is_array($value) || $value instanceof \Closure) {
                        $readableValue = 'callback';
                    }
                }
            }

            $readable[$readableKey] = $readableValue;
        }

        return $readable;
    }

    public function dataProvider()
    {
        $postBody = new QueryString(array(
            'file' => '@' . __DIR__ . '/../../phpunit.xml'
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
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: www.google.com'),
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
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_PORT => 8080,
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: 127.0.0.1:8080'),
            )),
            array('HEAD', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: www.google.com'),
                CURLOPT_CUSTOMREQUEST => 'HEAD',
                CURLOPT_NOBODY => 1
            )),
            array('GET', 'https://michael:123@www.guzzle-project.com/index.html?q=2', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: www.guzzle-project.com', 'Authorization: Basic ' . $auth),
                CURLOPT_PORT => 443
            )),
            array('GET', 'http://www.guzzle-project.com:8080/', array(
                    'X-Test-Data' => 'Guzzle'
                ), null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('X-Test-Data: Guzzle', 'User-Agent: ' . $userAgent, 'Host: www.guzzle-project.com:8080'),
                CURLOPT_PORT => 8080
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, $qs, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POSTFIELDS => 'x=y&z=a',
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('PUT', 'http://www.guzzle-project.com/put.php', null, EntityBody::factory(fopen(__DIR__ . '/../../phpunit.xml', 'r+')), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_READFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_INFILESIZE => filesize(__DIR__ . '/../../phpunit.xml'),
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Expect: 100-Continue',
                    'Content-Type: '
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, array(
                'a' => '2'
            ), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'a=2',
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
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
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'x=y',
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, $postBody, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => array(
                    'file' => '@' . __DIR__ . '/../../phpunit.xml'
                ),
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: multipart/form-data'
                )
            )),
        );
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     * @dataProvider dataProvider
     */
    public function testFactoryCreatesCurlResourceBasedOnRequest($method, $url, $headers, $body, $options)
    {
        $factory = RequestFactory::getInstance();
        $request = $factory->newRequest($method, $url, $headers, $body);
        $handle = $request->getCurlHandle();
        $this->assertType('Guzzle\\Http\\Curl\\CurlHandle', $handle);
        $o = $request->getCurlOptions()->getAll();

        foreach ($options as $key => $value) {
            $this->assertArrayHasKey($key, $o);
            if ($key != CURLOPT_HTTPHEADER && $key != CURLOPT_POSTFIELDS && (is_array($o[$key])) || $o[$key] instanceof \Closure) {
                $this->assertEquals('callback', $value);
            } else {
                $this->assertTrue($value == $o[$key]);
            }
        }

        $request->releaseCurlHandle();
        unset($request);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testFactoryUsesSpecifiedProtocol()
    {
        $factory = RequestFactory::getInstance();
        $request = $factory->newRequest('GET', 'http://www.guzzle-project.com/');
        $request->setProtocolVersion('1.1');
        $handle = CurlFactory::getInstance()->getHandle($request);
        $this->assertEquals(CURL_HTTP_VERSION_1_1, $request->getCurlOptions()->get(CURLOPT_HTTP_VERSION));
        $request->releaseCurlHandle();
        unset($request);
    }

    /**
     * Tests that a handle can be used for auth requests and non-auth requests
     * without mucking up sending credentials when it shouldn't
     *
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testFactoryCanReuseAuthHandles()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $host = Url::factory($this->getServer()->getUrl());
        $host = $host->getHost() . ':' . $host->getPort();

        $request = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $h1 = $request->getCurlHandle();
        $request->send();
        $this->assertEquals(
            "GET / HTTP/1.1\r\n" .
            "Accept: */*\r\n" .
            "Accept-Encoding: deflate, gzip\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: " . $host . "\r\n\r\n",
            (string) $request
        );
        
        $request->setState('new');
        $request->setAuth('michael', 'test');
        $h2 = $request->getCurlHandle();
        $request->send();
        $this->assertEquals(
            "GET / HTTP/1.1\r\n" .
            "Accept-Encoding: deflate, gzip\r\n" .
            "Accept: */*\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: " . $host . "\r\n" .
            "Authorization: Basic bWljaGFlbDp0ZXN0\r\n\r\n",
            (string) $request
        );
        
        $request->setState('new');
        $request->setAuth(false);
        $h3 = $request->getCurlHandle();
        $request->send();
        $this->assertEquals(
            "GET / HTTP/1.1\r\n" .
            "Accept-Encoding: deflate, gzip\r\n" .
            "Accept: */*\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: " . $host . "\r\n\r\n",
            (string) $request
        );
        
        $this->assertSame($h1, $h2);
        $this->assertSame($h1, $h3);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testUploadsDataUsingCurlAndCanReuseHandleAfterUpload()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi", // PUT response
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n",   // HEAD response
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi"  // POST response
        ));

        $host = Url::factory($this->getServer()->getUrl());
        $host = $host->getHost() . ':' . $host->getPort();

        $o = new MockObserver();
        $request = RequestFactory::getInstance()->newRequest('PUT', $this->getServer()->getUrl());
        $request->setBody(EntityBody::factory('test'));
        $request->getSubjectMediator()->attach($o);
        $h1 = $request->getCurlHandle();
        $request->send();

        // Make sure that the events were dispatched
        $this->assertArrayHasKey('curl.callback.read', $o->logByState);
        $this->assertArrayHasKey('curl.callback.write', $o->logByState);
        $this->assertArrayHasKey('curl.callback.progress', $o->logByState);

        // Make sure that the data was sent through the event
        $this->assertEquals('test', $o->logByState['curl.callback.read']);
        $this->assertEquals('hi', $o->logByState['curl.callback.write']);

        // Ensure that the request was received exactly as intended
        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals((string) $request, (string) $r[0]);

        // Create a new request and try to reuse the connection
        $request = RequestFactory::getInstance()->newRequest('HEAD', $this->getServer()->getUrl());
        $this->assertSame($h1, $request->getCurlHandle());
        $request->send();
        
        // Make sure that the request was sent correctly
        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals((string) $request, (string) $r[1]);

        // Create a new request using the same connection and POST
        $request = RequestFactory::getInstance()->newRequest('POST', $this->getServer()->getUrl());
        $request->addPostFields(array(
            'a' => 'b',
            'c' => 'ay! ~This is a test, isn\'t it?'
        ));
        $this->assertSame($h1, $request->getCurlHandle());
        $request->send();

        // Make sure that the request was sent correctly
        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals((string) $request, (string) $r[2]);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testClosesHandlesWhenHandlesAreReleasedAndNeedToBeClosed()
    {
        $f = CurlFactory::getInstance();
        $baseline = $f->getConnectionsPerHost(true, '127.0.0.1:8124');
        $request1 = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $request1->getCurlHandle();
        $request2 = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $request2->getCurlHandle();

        // Make sure tha allocated count went up
        $current = $f->getConnectionsPerHost(true, '127.0.0.1:8124');
        $this->assertEquals($baseline + 2, $current);

        // Release the handles so they are unallocated and cleaned back to 2
        $request1->releaseCurlHandle();
        $request2->releaseCurlHandle();

        $current = $f->getConnectionsPerHost(true, '127.0.0.1:8124');
        $this->assertEquals($baseline, $current);

        $current = $f->getConnectionsPerHost(false, '127.0.0.1:8124');
        $this->assertEquals(2, $current);

        $this->assertSame($f, $f->setMaxIdleForHost('127.0.0.1:8124', 1));
        $this->assertSame($f, $f->clean());
        $current = $f->getConnectionsPerHost(null, '127.0.0.1:8124');
        $this->assertEquals(1, $current);

        // Purge all unalloacted connections
        $f->clean(true);
        $this->assertEquals(array(), $f->getConnectionsPerHost(false));

        $request = RequestFactory::getInstance()->newRequest('HEAD', $this->getServer()->getUrl());
        $handle1 = $request->getCurlHandle();
        $this->assertEquals(1, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));
        $f->releaseHandle($handle1);
        $this->assertEquals(0, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));
        $this->assertEquals(1, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));
        // Relase and force close
        $f->releaseHandle($handle1, true);

        // Make sure that the handle was closed
        $this->assertEquals(0, $f->getConnectionsPerHost(null, '127.0.0.1:8124'));
        $request = RequestFactory::getInstance()->newRequest('HEAD', $this->getServer()->getUrl());
        $handle2 = $request->getCurlHandle();
        $this->assertNotSame($handle1, $handle2);
        $this->assertEquals(1, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));

        curl_close($handle2->getHandle());
        $f->releaseHandle($handle2);
        $this->assertEquals(0, $f->getConnectionsPerHost(null, '127.0.0.1:8124'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory::setMaxIdleTime
     * @covers Guzzle\Http\Curl\CurlFactory::clean
     */
    public function testPurgesConnectionsThatAreTooStaleBasedOnMaxIdleTime()
    {
        $f = CurlFactory::getInstance();
        $this->assertSame($f, $f->setMaxIdleTime(0));
        $request = RequestFactory::getInstance()->newRequest('HEAD', $this->getServer()->getUrl());
        $request->getCurlHandle();
        $this->assertEquals(1, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));
        $this->assertEquals(0, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));

        // By releasing the handle, the factory should clean up the handle
        // because of the max idle time
        $request->releaseCurlHandle();
        $this->assertEquals(0, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));

        // Set the default max idle time
        $f->setMaxIdleTime(-1);
    }
}