<?php
namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\ConnectException
 */
class ConnectExceptionTest extends TestCase
{
    public function testHasNoResponse()
    {
        $req = new Request('GET', '/');
        $prev = new \Exception();
        $e = new ConnectException('foo', $req, $prev, ['foo' => 'bar']);
        $this->assertSame($req, $e->getRequest());
        $this->assertNull($e->getResponse());
        $this->assertFalse($e->hasResponse());
        $this->assertSame('foo', $e->getMessage());
        $this->assertSame('bar', $e->getHandlerContext()['foo']);
        $this->assertSame($prev, $e->getPrevious());
    }
}
