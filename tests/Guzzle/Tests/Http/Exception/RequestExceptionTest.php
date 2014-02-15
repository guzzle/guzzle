<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;

/**
 * @covers Guzzle\Http\Exception\RequestException
 */
class RequestExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(200);
        $e = new RequestException('foo', $req, $res);
        $this->assertSame($req, $e->getRequest());
        $this->assertSame($res, $e->getResponse());
        $this->assertTrue($e->hasResponse());
        $this->assertEquals('foo', $e->getMessage());
    }

    public function testCreatesGenerateException()
    {
        $e = RequestException::create(new Request('GET', '/'));
        $this->assertEquals('Error completing request', $e->getMessage());
        $this->assertInstanceOf('Guzzle\Http\Exception\RequestException', $e);
    }

    public function testCreatesClientErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(400));
        $this->assertEquals(
            'Client error response [url] / [status code] 400 [reason phrase] Bad Request',
            $e->getMessage()
        );
        $this->assertInstanceOf('Guzzle\Http\Exception\ClientException', $e);
    }

    public function testCreatesServerErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        $this->assertEquals(
            'Server error response [url] / [status code] 500 [reason phrase] Internal Server Error',
            $e->getMessage()
        );
        $this->assertInstanceOf('Guzzle\Http\Exception\ServerException', $e);
    }

    public function testCreatesGenericErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(600));
        $this->assertEquals(
            'Unsuccessful response [url] / [status code] 600 [reason phrase] ',
            $e->getMessage()
        );
        $this->assertInstanceOf('Guzzle\Http\Exception\RequestException', $e);
    }
}
