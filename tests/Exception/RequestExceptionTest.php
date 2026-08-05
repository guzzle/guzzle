<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\BodySummarizerInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\DiagnosticValue;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;

/**
 * @covers \GuzzleHttp\Exception\RequestException
 */
class RequestExceptionTest extends TestCase
{
    public function testHasRequest(): void
    {
        $req = new Request('GET', '/');
        $e = new RequestException('foo', $req);
        self::assertInstanceOf(RequestExceptionInterface::class, $e);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        self::assertSame($req, $e->getRequest());
        self::assertSame('foo', $e->getMessage());
        self::assertSame(0, $e->getCode());
    }

    public function testCreatesGenerateException(): void
    {
        $e = RequestException::create(new Request('GET', '/'));
        self::assertSame('Error completing request', $e->getMessage());
        self::assertInstanceOf(RequestException::class, $e);
    }

    public function testCreatesClientErrorResponseException(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(400));
        self::assertStringContainsString(
            'GET /',
            $e->getMessage()
        );
        self::assertStringContainsString(
            '400 Bad Request',
            $e->getMessage()
        );
        self::assertInstanceOf(ClientException::class, $e);
    }

    public function testCreatesServerErrorResponseException(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        self::assertStringContainsString(
            'GET /',
            $e->getMessage()
        );
        self::assertStringContainsString(
            '500 Internal Server Error',
            $e->getMessage()
        );
        self::assertInstanceOf(ServerException::class, $e);
    }

    public function testEscapesUnsafeGeneratedResponseDiagnosticsWithoutChangingTheResponse(): void
    {
        $reason = "Internal \u{009B}Error";
        $response = new Response(500, [], 'raw body', '1.1', $reason);
        $summarizer = new class implements BodySummarizerInterface {
            public function summarize(MessageInterface $message): ?string
            {
                return "summary\x1B\xFF";
            }
        };

        $e = RequestException::create(new Request('GET', '/'), $response, null, $summarizer);

        self::assertSame('Server error: `GET /` resulted in a `500 Internal \\x9BError` response: summary\\x1B\\xFF', $e->getMessage());
        self::assertSame($response, $e->getResponse());
        self::assertSame($reason, $e->getResponse()->getReasonPhrase());
        self::assertSame('raw body', (string) $e->getResponse()->getBody());
    }

    public function testCreatesGenericErrorResponseException(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(300));
        self::assertStringContainsString(
            'GET /',
            $e->getMessage()
        );
        self::assertStringContainsString(
            '300 ',
            $e->getMessage()
        );
        self::assertInstanceOf(ResponseException::class, $e);
    }

    public function testThrowsInvalidArgumentExceptionOnOutOfBoundsResponseCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Status code must be an integer value between 1xx and 5xx.');

        throw RequestException::create(new Request('GET', '/'), new Response(600));
    }

    public static function dataPrintableResponses(): array
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
    public function testCreatesExceptionWithPrintableBodySummary(string $content): void
    {
        $response = new Response(
            500,
            [],
            $content
        );
        $e = RequestException::create(new Request('GET', '/'), $response);
        self::assertStringContainsString(
            DiagnosticValue::escape($content),
            $e->getMessage()
        );
        self::assertInstanceOf(RequestException::class, $e);
    }

    public function testCreatesExceptionWithTruncatedSummary(): void
    {
        $content = \str_repeat('+', 121);
        $response = new Response(500, [], $content);
        $e = RequestException::create(new Request('GET', '/'), $response);
        $expected = \str_repeat('+', 120).' (truncated...)';
        self::assertStringContainsString($expected, $e->getMessage());
    }

    public function testExceptionMessageIgnoresEmptyBody(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(500));
        self::assertStringEndsWith('response', $e->getMessage());
    }

    public function testHasStatusCodeAsExceptionCode(): void
    {
        $e = RequestException::create(new Request('GET', '/'), new Response(442));
        self::assertSame(442, $e->getCode());
    }

    public function testObfuscateUrlWithToken(): void
    {
        $r = new Request('GET', 'http://secret-token@www.oo.com');
        $e = RequestException::create($r, new Response(500));
        self::assertStringContainsString('http://***@www.oo.com', $e->getMessage());
        self::assertStringNotContainsString('secret-token', $e->getMessage());
    }

    public function testObfuscateUrlWithUsernameAndPassword(): void
    {
        $r = new Request('GET', 'http://user:password@www.oo.com');
        $e = RequestException::create($r, new Response(500));
        self::assertStringContainsString('http://***@www.oo.com', $e->getMessage());
        self::assertStringNotContainsString('password', $e->getMessage());
    }

    public function testRedactsSensitiveUriComponentsWithoutRemovingBodySummary(): void
    {
        $request = new Request('POST', 'https://user:password@example.test/token?tenant=acme&code=secret#trace');
        $response = new Response(401, [], '{"error":"BODY_PREFIX_MARKER"}');

        $e = RequestException::create($request, $response);

        self::assertSame('Client error: `POST https://***@example.test/token` resulted in a `401 Unauthorized` response: {"error":"BODY_PREFIX_MARKER"}', $e->getMessage());
        self::assertSame($request, $e->getRequest());
        self::assertSame($response, $e->getResponse());
        self::assertTrue($response->getBody()->isReadable());
        self::assertSame('{"error":"BODY_PREFIX_MARKER"}', (string) $response->getBody());
    }
}

final class ReadSeekOnlyStream extends Stream
{
    public function __construct()
    {
        parent::__construct(\fopen('php://memory', 'wb'));
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return false;
    }
}
