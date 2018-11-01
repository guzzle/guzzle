<?php
namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Exception\RequestException
 */
class RequestExceptionTest extends TestCase
{
    public function testHasRequestAndResponse()
    {
        $req = new Request('GET', '/');
        $res = new Response(200);
        $e = new RequestException('foo', $req, $res);
        $this->assertSame($req, $e->getRequest());
        $this->assertSame($res, $e->getResponse());
        $this->assertTrue($e->hasResponse());
        $this->assertSame('foo', $e->getMessage());
    }

    public function testCreatesGenerateException()
    {
        $e = RequestException::create(new Request('GET', '/'));
        $this->assertSame('Error completing request', $e->getMessage());
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testCreatesClientErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(400));
        $this->assertContains(
            'GET /',
            $e->getMessage()
        );
        $this->assertContains(
            '400 Bad Request',
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\ClientException', $e);
    }

    public function testCreatesServerErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        $this->assertContains(
            'GET /',
            $e->getMessage()
        );
        $this->assertContains(
            '500 Internal Server Error',
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\ServerException', $e);
    }

    public function testCreatesGenericErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(600));
        $this->assertContains(
            'GET /',
            $e->getMessage()
        );
        $this->assertContains(
            '600 ',
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function dataPrintableResponses()
    {
        return [
            ['You broke the test!'],
            ['<h1>zlomený zkouška</h1>'],
            ['{"tester": "Philépe Gonzalez"}'],
            ["<xml>\n\t<text>Your friendly test</text>\n</xml>"],
            ['document.body.write("here comes a test");'],
            ["body:before {\n\tcontent: 'test style';\n}"],
        ];
    }

    /**
     * @dataProvider dataPrintableResponses
     */
    public function testCreatesExceptionWithPrintableBodySummary($content)
    {
        $response = new Response(
            500,
            [],
            $content
        );
        $e = RequestException::create(new Request('GET', '/'), $response);
        $this->assertContains(
            $content,
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testCreatesExceptionWithTruncatedSummary()
    {
        $content = str_repeat('+', 121);
        $response = new Response(500, [], $content);
        $e = RequestException::create(new Request('GET', '/'), $response);
        $expected = str_repeat('+', 120) . ' (truncated...)';
        $this->assertContains($expected, $e->getMessage());
    }

    public function testExceptionMessageIgnoresEmptyBody()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        $this->assertStringEndsWith('response', $e->getMessage());
    }

    public function testCreatesExceptionWithoutPrintableBody()
    {
        $response = new Response(
            500,
            ['Content-Type' => 'image/gif'],
            $content = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') // 1x1 gif
        );
        $e = RequestException::create(new Request('GET', '/'), $response);
        $this->assertNotContains(
            $content,
            $e->getMessage()
        );
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testHasStatusCodeAsExceptionCode()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(442));
        $this->assertSame(442, $e->getCode());
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
        $this->assertSame(['bar' => 'baz'], $e->getHandlerContext());
    }

    public function testObfuscateUrlWithUsername()
    {
        $r = new Request('GET', 'http://username@www.oo.com');
        $e = RequestException::create($r, new Response(500));
        $this->assertContains('http://username@www.oo.com', $e->getMessage());
    }

    public function testObfuscateUrlWithUsernameAndPassword()
    {
        $r = new Request('GET', 'http://user:password@www.oo.com');
        $e = RequestException::create($r, new Response(500));
        $this->assertContains('http://user:***@www.oo.com', $e->getMessage());
    }

    public function testGetResponseBodySummaryOfNonReadableStream()
    {
        $this->assertNull(RequestException::getResponseBodySummary(new Response(500, [], new ReadSeekOnlyStream())));
    }
}

final class ReadSeekOnlyStream extends Stream
{
    public function __construct()
    {
        parent::__construct(fopen('php://memory', 'wb'));
    }

    public function isSeekable()
    {
        return true;
    }

    public function isReadable()
    {
        return false;
    }
}
