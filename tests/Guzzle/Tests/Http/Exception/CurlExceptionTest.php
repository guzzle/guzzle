<?php

namespace Guzzle\Tests\Http\Exception;

use Guzzle\Http\Exception\CurlException;

/**
 * @covers Guzzle\Http\Exception\CurlException
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
