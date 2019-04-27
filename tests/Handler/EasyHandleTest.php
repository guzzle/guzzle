<?php

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\EasyHandle
 */
class EasyHandleTest extends TestCase
{
    public function testEnsuresHandleExists()
    {
        $easy = new EasyHandle;

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The EasyHandle is not initialized');

        $easy->handle;
    }

    public function testGetReleasedHandle(): void
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        curl_close($easy->handle);
        unset($easy->handle);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The EasyHandle has been released');

        $easy->handle;
    }

    public function testGetPropertyAfterHandleRelease(): void
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        curl_close($easy->handle);
        unset($easy->handle);

        self::assertSame([], $easy->options);
    }

    public function testSettingBadHandle(): void
    {
        $easy = new EasyHandle;

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Property GuzzleHttp\Handler\EasyHandle::$handle can only accept a resource of type "curl"');

        $easy->handle = null;
    }

    public function testSettingPropertyOnReleasedHandle(): void
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        curl_close($easy->handle);
        unset($easy->handle);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The EasyHandle has been released, please use a new EasyHandle instead');

        $easy->options = [];
    }

    public function testSettingHandleTwice(): void
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        curl_close($easy->handle);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Property GuzzleHttp\Handler\EasyHandle::$handle is already set, please use a new EasyHandle instead');

        $easy->handle = curl_init();
    }

    public function testSettingProperties(): void
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

        self::assertSame($handle, $easy->handle);
        self::assertSame($stream, $easy->sink);
        self::assertSame($headers, $easy->headers);
        self::assertSame($request, $easy->request);
        self::assertSame($options, $easy->options);
        curl_close($easy->handle);
        unset($handle, $stream, $easy->handle);
    }

    public function testSettingHeadersWithoutHandle(): void
    {
        $easy = new EasyHandle;

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Property GuzzleHttp\Handler\EasyHandle::$headers could not be set when there isn\'t a valid handle');

        $easy->headers = [];
    }

    public function testSettingErrnoWithHandle(): void
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        $easy->errno = \CURLE_OK;

        self::assertSame(\CURLE_OK, $easy->errno);

        curl_close($easy->handle);
        unset($easy->handle);
    }

    public function testChangingHandleErrno(): void
    {
        $easy = new EasyHandle;
        $easy->handle = curl_init();
        curl_exec($easy->handle);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Property GuzzleHttp\Handler\EasyHandle::$errno could not be set with 0 since the handle is reporting error 3');

        $easy->errno = \CURLE_OK;

        self::assertSame(\CURLE_OK, $easy->errno);

        curl_close($easy->handle);

        unset($easy->handle);
    }

    public function testSettingResponse(): void
    {
        $easy = new EasyHandle;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot set private property GuzzleHttp\Handler\EasyHandle::$response');

        $easy->response = new Psr7\Response();
    }

    public function testSettingErrnoWithoutHandle()
    {
        $easy = new EasyHandle;

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Property GuzzleHttp\Handler\EasyHandle::$errno could not be set when there isn\'t a valid handle');

        $easy->errno = \CURLE_COULDNT_RESOLVE_HOST;
    }

    public function testGetInvalidProperty(): void
    {
        $easy = new EasyHandle;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Undefined property: GuzzleHttp\Handler\EasyHandle::$nonexistent');

        $easy->nonexistent;
    }

    public function testPropertyOverload(): void
    {
        $overloadedValue = 42;

        $easy = new EasyHandle;
        $easy->nonexistent = $overloadedValue;

        self::assertSame($overloadedValue, $easy->nonexistent);
    }
}
