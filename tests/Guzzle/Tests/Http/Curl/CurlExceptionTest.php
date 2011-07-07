<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Http\Curl\CurlException;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlExceptionTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Curl\CurlException
     */
    public function testStoresCurlError()
    {
        $e = new CurlException();
        $this->assertNull($e->getCurlError());
        $this->assertSame($e, $e->setCurlError('test'));
        $this->assertEquals('test', $e->getCurlError());
    }
}