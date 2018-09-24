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
    /**
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessage The EasyHandle has been released
     */
    public function testEnsuresHandleExists()
    {
        $easy = new EasyHandle;
        unset($easy->handle);
        $easy->handle;
    }

    public function testZeroStatusCodeIfStatusCodeMissingInHeaders()
    {
        $easy = new EasyHandle();
        $headers = [
            "HTTP/1.0",
            "Server: Quick 'n Easy Web Server",
            "Connection: Keep-Alive",
            "Content-Length: 0",
        ];
        $easy->headers = $headers;
        $easy->createResponse();
        $easy->response;
        $this->assertEquals(0, $easy->response->getStatusCode());
    }
}
