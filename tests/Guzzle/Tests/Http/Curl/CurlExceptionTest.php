<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Http\Curl\CurlException;

/**
 * @covers Guzzle\Http\Curl\CurlException
 */
class CurlExceptionTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testStoresCurlError()
    {
        $e = new CurlException();
        $this->assertNull($e->getError());
        $this->assertNull($e->getErrorNo());
        $this->assertSame($e, $e->setError('test', 12));
        $this->assertEquals('test', $e->getError());
        $this->assertEquals(12, $e->getErrorNo());
    }
}