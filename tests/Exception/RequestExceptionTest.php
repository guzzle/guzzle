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
        self::assertSame($req, $e->getRequest());
        self::assertSame($res, $e->getResponse());
        self::assertTrue($e->hasResponse());
        self::assertSame('foo', $e->getMessage());
    }

    public function testCreatesGenerateException()
    {
        $e = RequestException::create(new Request('GET', '/'));
        self::assertSame('Error completing request', $e->getMessage());
        self::assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testCreatesClientErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(400));
        self::assertContains(
            'GET /',
            $e->getMessage()
        );
        self::assertContains(
            '400 Bad Request',
            $e->getMessage()
        );
        self::assertInstanceOf('GuzzleHttp\Exception\ClientException', $e);
    }

    public function testCreatesServerErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        self::assertContains(
            'GET /',
            $e->getMessage()
        );
        self::assertContains(
            '500 Internal Server Error',
            $e->getMessage()
        );
        self::assertInstanceOf('GuzzleHttp\Exception\ServerException', $e);
    }

    public function testCreatesGenericErrorResponseException()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(300));
        self::assertContains(
            'GET /',
            $e->getMessage()
        );
        self::assertContains(
            '300 ',
            $e->getMessage()
        );
        self::assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Status code must be an integer value between 1xx and 5xx.
     */
    public function testThrowsInvalidArgumentExceptionOnOutOfBoundsResponseCode()
    {
        throw RequestException::create(new Request('GET', '/'), new Response(600));
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
        self::assertContains(
            $content,
            $e->getMessage()
        );
        self::assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
    }

    public function testCreatesExceptionWithTruncatedSummary()
    {
        $content = str_repeat('+', 121);
        $response = new Response(500, [], $content);
        $e = RequestException::create(new Request('GET', '/'), $response);
        $expected = str_repeat('+', 120) . ' (truncated...)';
        self::assertContains($expected, $e->getMessage());
    }

    public function testExceptionMessageIgnoresEmptyBody()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        self::assertStringEndsWith('response', $e->getMessage());
    }

    public function testHasStatusCodeAsExceptionCode()
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(442));
        self::assertSame(442, $e->getCode());
    }

    public function testWrapsRequestExceptions()
    {
        $e = new \Exception('foo');
        $r = new Request('GET', 'http://www.oo.com');
        $ex = RequestException::wrapException($r, $e);
        self::assertInstanceOf('GuzzleHttp\Exception\RequestException', $ex);
        self::assertSame($e, $ex->getPrevious());
    }

    public function testDoesNotWrapExistingRequestExceptions()
    {
        $r = new Request('GET', 'http://www.oo.com');
        $e = new RequestException('foo', $r);
        $e2 = RequestException::wrapException($r, $e);
        self::assertSame($e, $e2);
    }

    public function testCanProvideHandlerContext()
    {
        $r = new Request('GET', 'http://www.oo.com');
        $e = new RequestException('foo', $r, null, null, ['bar' => 'baz']);
        self::assertSame(['bar' => 'baz'], $e->getHandlerContext());
    }

    public function testObfuscateUrlWithUsername()
    {
        $r = new Request('GET', 'http://username@www.oo.com');
        $e = RequestException::create($r, new Response(500));
        self::assertContains('http://username@www.oo.com', $e->getMessage());
    }

    public function testObfuscateUrlWithUsernameAndPassword()
    {
        $r = new Request('GET', 'http://user:password@www.oo.com');
        $e = RequestException::create($r, new Response(500));
        self::assertContains('http://user:***@www.oo.com', $e->getMessage());
    }

    public function testGetResponseBodySummaryOfNonReadableStream()
    {
        self::assertNull(RequestException::getResponseBodySummary(new Response(500, [], new ReadSeekOnlyStream())));
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
