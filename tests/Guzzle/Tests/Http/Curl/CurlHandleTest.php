<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Common\Collection;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Curl\CurlFactory;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
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
       $this->assertEquals($this->getServer()->getUrl(), $ha->getOption(CURLOPT_URL));

       $ha = new CurlHandle($h, new Collection(array(
           CURLOPT_URL => $this->getServer()->getUrl()
       )));
       $this->assertEquals($this->getServer()->getUrl(), $ha->getOption(CURLOPT_URL));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::__construct
     * @covers Guzzle\Http\Curl\CurlHandle::getStderr
     * @covers Guzzle\Http\Curl\CurlHandle::getHandle
     * @covers Guzzle\Http\Curl\CurlHandle::getUrl
     * @covers Guzzle\Http\Curl\CurlHandle::getOptions
     * @covers Guzzle\Http\Curl\CurlHandle::getOption
     * @covers Guzzle\Http\Curl\CurlHandle::getIdleTime
     * @covers Guzzle\Http\Curl\CurlHandle::getOwner
     */
    public function testConstructorInitializesObject()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => $this->getServer()->getUrl()
        ));

        $this->assertInternalType('resource', $h->getStderr(true));
        $this->assertInternalType('string', $h->getStderr(false));
        $r = $h->getStderr(true);
        fwrite($r, 'test');
        $this->assertEquals('test', $h->getStderr(false));

        $this->assertInstanceOf('Guzzle\\Http\\Url', $h->getUrl());
        $this->assertEquals($this->getServer()->getUrl(), (string) $h->getUrl());
        $this->assertSame($handle, $h->getHandle());

        $this->assertEquals($this->getServer()->getUrl(), $h->getOption(CURLOPT_URL));
        $this->assertEquals(array(
            CURLOPT_VERBOSE => true,
            CURLOPT_URL => $this->getServer()->getUrl(),
            CURLOPT_STDERR => $h->getStderr(true)
        ), $h->getOptions());

        $this->assertTrue($h->getIdleTime() == 0 || $h->getIdleTime() == 1);

        $this->assertNull($h->getOwner());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::isAvailable
     * @covers Guzzle\Http\Curl\CurlHandle::isMyHandle
     */
    public function testDeterminesIfResourceIsAvailable()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array());
        $this->assertTrue($h->isAvailable());
        $this->assertTrue($h->isMyHandle($handle));

        // Mess it up by closing the handle
        curl_close($handle);

        $this->assertFalse($h->isAvailable());
        $this->assertFalse($h->isMyHandle($handle));

        unset($handle);
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
     * @covers Guzzle\Http\Curl\CurlHandle::setOption
     * @covers Guzzle\Http\Curl\CurlHandle::setOptions
     * @covers Guzzle\Http\Curl\CurlHandle::getOption
     */
    public function testWrapsSettingOptions()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array());
        $this->assertSame($h, $h->setOption(CURLOPT_AUTOREFERER, true));
        $this->assertSame($h, $h->setOption('CURLOPT_BUFFERSIZE', 1024));

        $this->assertEquals(true, $h->getOption(CURLOPT_AUTOREFERER));
        $this->assertEquals(1024, $h->getOption('CURLOPT_BUFFERSIZE'));
        $this->assertEquals(1024, $h->getOption(CURLOPT_BUFFERSIZE));

        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array());
        $this->assertSame($h, $h->setOptions(array(
            CURLOPT_AUTOREFERER => true,
            'CURLOPT_BUFFERSIZE' => 1024
        )));

        $this->assertEquals(true, $h->getOption(CURLOPT_AUTOREFERER));
        $this->assertEquals(1024, $h->getOption('CURLOPT_BUFFERSIZE'));
        $this->assertEquals(1024, $h->getOption(CURLOPT_BUFFERSIZE));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::hasProblematicOption
     */
    public function testTestsForConnectionReuseBasedOnOptions()
    {
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => $this->getServer()->getUrl()
        ));
        $this->assertFalse($h->hasProblematicOption());
        $h->setOption(CURLOPT_TIMEOUT, 2);
        $this->assertTrue($h->hasProblematicOption());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::checkout
     * @covers Guzzle\Http\Curl\CurlHandle::unlock
     */
    public function testCanBeClaimedAndUnclaimed()
    {
        $request = RequestFactory::get($this->getServer()->getUrl());

        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => $this->getServer()->getUrl()
        ));

        $this->assertSame($h, $h->checkout($request));
        $this->assertEquals(0, $h->getIdleTime());
        $this->assertSame($request, $h->getOwner());

        $h->unlock();
        $this->assertTrue($h->isAvailable());

        // Unlock with a Connection: close request
        $request->setHeader('Connection', 'close');
        $h->checkout($request);
        $h->unlock();
        $this->assertFalse($h->isAvailable());

        // Unlock with a Connection: close response
        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => $this->getServer()->getUrl()
        ));
        $this->assertTrue($h->isAvailable());
        $request->removeHeader('Connection');
        $request->setResponse(Response::factory(
            "HTTP/1.1 200 OK\r\n" .
            "Connection: close\r\n\r\n"
        ));
        $h->checkout($request);
        $h->unlock();
        $this->assertFalse($h->isAvailable());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::isCompatible
     */
    public function testChecksIfRequestIsCompatibleForConnectionReuse()
    {
        $request = RequestFactory::get($this->getServer()->getUrl());

        $handle = curl_init($this->getServer()->getUrl());
        $h = new CurlHandle($handle, array(
            CURLOPT_URL => $this->getServer()->getUrl()
        ));

        // Connection reuse match
        $this->assertTrue($h->isCompatible($request));

        // Different ports
        $request->setPort(8192);
        $this->assertFalse($h->isCompatible($request));

        // Different CURLOPT_PORT
        $request->setPort(80);
        $request->getCurlOptions()->set(CURLOPT_PORT, 8192);
        $this->assertFalse($h->isCompatible($request));

        // Different host
        $request->setPort(80);
        $request->setHost('google.com');
        $this->assertFalse($h->isCompatible($request));

        // Different proxy server
        $request->getCurlOptions()->clear();
        $request->setUrl($this->getServer()->getUrl());
        $this->assertTrue($h->isCompatible($request));
        $request->getCurlOptions()->set(CURLOPT_PROXY, 'tcp://test.com:8080/');
        $this->assertFalse($h->isCompatible($request));

        // Using the same proxy
        $h->setOption(CURLOPT_PROXY, 'tcp://test.com:8080/');
        $this->assertTrue($h->isCompatible($request));

        // It's the ower of the handle
        $h->checkout($request);
        $this->assertTrue($h->isCompatible($request));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getUseCount
     * @covers Guzzle\Http\Curl\CurlHandle::setMaxReuses
     * @covers Guzzle\Http\Curl\CurlHandle::unlock
     */
    public function testClosesAfterMaxConnectionReusesIsExceeded()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ));

        CurlFactory::getInstance()->releaseAllHandles(true);
        $request = RequestFactory::get($this->getServer()->getUrl());
        $h = $request->getCurlHandle()->setMaxReuses(2);
        $curlHandle = $h->getHandle();
        $this->assertEquals(0, $h->getUseCount());
        
        $request->send();
        $this->assertEquals(1, $h->getUseCount());
        $this->assertSame($curlHandle, $h->getHandle());
        $request->send();
        $this->assertEquals(2, $h->getUseCount());
        $this->assertSame($curlHandle, $h->getHandle());
        $request->send();
        $this->assertEquals(0, $h->getUseCount());
        $this->assertNotSame($curlHandle, $h->getHandle());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlHandle::getUseCount
     * @covers Guzzle\Http\Curl\CurlHandle::setMaxReuses
     * @covers Guzzle\Http\Curl\CurlHandle::unlock
     */
    public function testCanCloseAfterOneConnectionReusesIsExceeded()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        CurlFactory::getInstance()->releaseAllHandles(true);
        $request = RequestFactory::get($this->getServer()->getUrl());
        $h = $request->getCurlHandle()->setMaxReuses(0);
        $this->assertNotNull($h->getHandle());
        $request->send();
        $this->assertNull($h->getHandle());
    }
}