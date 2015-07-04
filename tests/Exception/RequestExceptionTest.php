<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * @covers GuzzleHttp\Exception\RequestException
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
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testCreatesClientErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(400));
        $this->assertEquals(
            'Client error response [url] / [http method] GET [status code] 400 [reason phrase] Bad Request',
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\ClientException', $e);
    }

    public function testCreatesServerErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        $this->assertEquals(
            'Server error response [url] / [http method] GET [status code] 500 [reason phrase] Internal Server Error',
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\ServerException', $e);
    }

    public function testCreatesGenericErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(600));
        $this->assertEquals(
            'Unsuccessful response [url] / [http method] GET [status code] 600 [reason phrase] ',
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testHasStatusCodeAsExceptionCode() {
        $e = RequestException::create(new Request('GET', '/'), new Response(442));
        $this->assertEquals(442, $e->getCode());
    }

    public function testWrapsRequestExceptions()
    {
        $e = new \Exception('foo');
        $r = new Request('GET', 'http://www.oo.com');
        $ex = RequestException::wrapException($r, $e);
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $ex);
        $this->assertSame($e, $ex->getPrevious());
    }

    public function testDoesNotWrapExistingRequestExceptions()
    {
        $r = new Request('GET', 'http://www.oo.com');
        $e = new RequestException('foo', $r);
        $e2 = RequestException::wrapException($r, $e);
        $this->assertSame($e, $e2);
    }

    public function testCanProvideHandlerContext()
    {
        $r = new Request('GET', 'http://www.oo.com');
        $e = new RequestException('foo', $r, null, null, ['bar' => 'baz']);
        $this->assertEquals(['bar' => 'baz'], $e->getHandlerContext());
    }
}
