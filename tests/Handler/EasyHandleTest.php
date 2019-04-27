<?php

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\EasyHandle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\EasyHandle
 */
class EasyHandleTest extends TestCase
{
    public function testEnsuresHandleExists()
    {
        $easy = new EasyHandle;
        unset($easy->handle);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The EasyHandle has been released');
        $easy->handle;
    }

    public function testSettingProperties()
    {
        $handle = curl_init();
        $stream = new Psr7\Stream(fopen('php://temp', 'r'));
        $headers = [];
        $request = new Psr7\Request('HEAD', '/');
        $options = [];
        $easy = new EasyHandle;
        $easy->handle = $handle;
        $easy->sink = $stream;
        $easy->headers = $headers;
        $easy->request = $request;
        $easy->options = $options;

        $this->assertSame($handle, $easy->handle);
        $this->assertSame($stream, $easy->sink);
        $this->assertSame($headers, $easy->headers);
        $this->assertSame($request, $easy->request);
        $this->assertSame($options, $easy->options);
        curl_close($easy->handle);
        unset($handle, $stream, $easy->handle);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Property GuzzleHttp\Handler\EasyHandle::$headers could not be set when there isn't a valid handle
     */
    public function testSettingHeadersWithoutHandle()
    {
        $easy = new EasyHandle;
        $easy->headers = [];
    }

    public function testSettingErrnoWithHandle()
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        $easy->errno = CURLE_OK;

        $this->assertSame(CURLE_OK, $easy->errno);

        curl_close($easy->handle);
        unset($easy->handle);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Property GuzzleHttp\Handler\EasyHandle::$errno could not be set with 0 since the handle is reporting error 3
     */
    public function testChangingHandleErrno()
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        curl_exec($easy->handle);
        $easy->errno = CURLE_OK;

        $this->assertSame(CURLE_OK, $easy->errno);

        curl_close($easy->handle);
        unset($easy->handle);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot set private property GuzzleHttp\Handler\EasyHandle::$response
     */
    public function testSettingResponse()
    {
        $easy = new EasyHandle;
        $easy->response = new Psr7\Response();
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Property GuzzleHttp\Handler\EasyHandle::$errno could not be set when there isn't a valid handle
     */
    public function testSettingErrnoWithoutHandle()
    {
        $easy = new EasyHandle;
        $easy->errno = CURLE_COULDNT_RESOLVE_HOST;
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Undefined property: GuzzleHttp\Handler\EasyHandle::$nonexistent
     */
    public function testGetInvalidProperty()
    {
        $easy = new EasyHandle;
        $easy->nonexistent;
    }

    public function testPropertyOverload()
    {
        $overloadedValue = 42;

        $easy = new EasyHandle;
        $easy->nonexistent = $overloadedValue;

        $this->assertSame($overloadedValue, $easy->nonexistent);
    }
}
