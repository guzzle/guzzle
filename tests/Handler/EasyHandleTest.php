<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Tests\Psr17SpyFactory;
use GuzzleHttp\Tests\SpyResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\EasyHandle
 */
class EasyHandleTest extends TestCase
{
    public function testEnsuresHandleExists(): void
    {
        $easy = new EasyHandle();
        unset($easy->handle);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The EasyHandle has been released');
        $easy->handle;
    }

    public function testEffectiveProxyDefaultsToNull(): void
    {
        $easy = new EasyHandle();

        self::assertNull($easy->effectiveProxy);
    }

    public function testCreateResponseIgnoresInterim1xxResponses(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 103 Early Hints', 'Link: </style.css>; rel=preload'];

        $easy->createResponse();
        self::assertNull($easy->response, 'an interim 1xx must not be stored as the response');

        $easy->headers = ['HTTP/1.1 200 OK', 'Content-Length: 0'];
        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertSame(200, $easy->response->getStatusCode());
    }

    public function testCreateResponseIgnores100Continue(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 100 Continue'];

        $easy->createResponse();

        self::assertNull($easy->response);
    }

    public function testCreateResponsePreserves101SwitchingProtocols(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 101 Switching Protocols', 'Upgrade: websocket', 'Connection: Upgrade'];

        $easy->createResponse();

        self::assertNotNull($easy->response, '101 is terminal and must be kept as a response');
        self::assertSame(101, $easy->response->getStatusCode());
    }

    public function testInvalidResponseFramingSkipsDecodedHeaderRewrites(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('encoded');
        $easy->headers = [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: 7',
            'Transfer-Encoding: chunked',
        ];
        $easy->options = ['decode_content' => true];

        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertInstanceOf(ResponseTransferException::class, $easy->responseHeaderException);
        self::assertSame($easy->response, $easy->responseHeaderException->getResponse());
        self::assertSame('Response contains both Transfer-Encoding and Content-Length', $easy->responseHeaderException->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $easy->responseHeaderException->getPrevious());
        self::assertSame('gzip', $easy->response->getHeaderLine('Content-Encoding'));
        self::assertSame('7', $easy->response->getHeaderLine('Content-Length'));
        self::assertSame('chunked', $easy->response->getHeaderLine('Transfer-Encoding'));
        self::assertFalse($easy->response->hasHeader('x-encoded-content-encoding'));
        self::assertFalse($easy->response->hasHeader('x-encoded-content-length'));
    }

    public function testCreateResponseStoresOverflowAsPlainResponseException(): void
    {
        $overflow = ((string) \PHP_INT_MAX).'0';
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 200 OK', 'Content-Length: '.$overflow];

        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertInstanceOf(ResponseException::class, $easy->responseHeaderException);
        self::assertNotInstanceOf(ResponseTransferException::class, $easy->responseHeaderException);
        self::assertInstanceOf(\OverflowException::class, $easy->responseHeaderException->getPrevious());
        self::assertSame($overflow, $easy->response->getHeaderLine('Content-Length'));
    }

    public function testBodilessResponseIgnoresInvalidFramingAndClearsEarlierFailure(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 200 OK', 'Content-Length: bad'];
        $easy->createResponse();
        self::assertInstanceOf(ResponseTransferException::class, $easy->responseHeaderException);

        $easy->request = new Psr7\Request('HEAD', 'http://example.com');
        $easy->headers = [
            'HTTP/1.1 200 OK',
            'Content-Length: bad',
            'Transfer-Encoding: chunked',
        ];
        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertNull($easy->responseHeaderException);
        self::assertSame('bad', $easy->response->getHeaderLine('Content-Length'));
        self::assertSame('chunked', $easy->response->getHeaderLine('Transfer-Encoding'));
    }

    public function testDecodedContentLengthIsOmittedWhenSinkSizeOverflows(): void
    {
        $easy = self::createEasyHandle();
        $easy->headers = [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: 3',
        ];
        $easy->options = ['decode_content' => true];
        $easy->sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): int {
                throw new \OverflowException('too large');
            },
        ]);

        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertFalse($easy->response->hasHeader('Content-Encoding'));
        self::assertFalse($easy->response->hasHeader('Content-Length'));
        self::assertSame('gzip', $easy->response->getHeaderLine('x-encoded-content-encoding'));
        self::assertSame('3', $easy->response->getHeaderLine('x-encoded-content-length'));
    }

    public function testDecodedContentLengthCombinesMixedCaseFields(): void
    {
        $easy = self::createEasyHandle();
        $easy->headers = [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: 3',
            'content-length: 3',
        ];
        $easy->options = ['decode_content' => true];
        $easy->sink = Psr7\Utils::streamFor('decoded');

        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertSame('7', $easy->response->getHeaderLine('Content-Length'));
        self::assertSame(['3', '3'], $easy->response->getHeader('x-encoded-content-length'));
    }

    public function testZeroStringDecodeContentPreservesEncodedHeaders(): void
    {
        $easy = self::createEasyHandle();
        $easy->headers = [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: 4',
        ];
        $easy->sink = Psr7\Utils::streamFor('decoded');
        $easy->options = ['decode_content' => '0'];

        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertSame('gzip', $easy->response->getHeaderLine('x-encoded-content-encoding'));
        self::assertSame('4', $easy->response->getHeaderLine('x-encoded-content-length'));
        self::assertFalse($easy->response->hasHeader('content-encoding'));
    }

    public function testCreateResponseIsBuiltViaConfiguredResponseFactory(): void
    {
        $factory = new Psr17SpyFactory();
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('hi');
        $easy->headers = ['HTTP/1.1 200 OK', 'Foo: Bar'];
        $easy->options = [RequestOptions::RESPONSE_FACTORY => $factory];

        $easy->createResponse();

        self::assertInstanceOf(SpyResponse::class, $easy->response);
        self::assertSame(1, $factory->createResponseCalls);
        self::assertSame(200, $easy->response->getStatusCode());
        self::assertSame('Bar', $easy->response->getHeaderLine('Foo'));
        self::assertSame('hi', (string) $easy->response->getBody());
    }

    public function testCreateResponsePreservesMixedCaseDuplicateHeaders(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 200 OK', 'Set-Cookie: a=1', 'set-cookie: b=2'];

        $easy->createResponse();

        self::assertNotNull($easy->response);
        self::assertSame(['a=1', 'b=2'], $easy->response->getHeader('Set-Cookie'));
    }

    public function testCreateResponsePropagatesResponseFactoryExceptions(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 200 OK'];
        $easy->options = [
            RequestOptions::RESPONSE_FACTORY => new class implements ResponseFactoryInterface {
                public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
                {
                    throw new \RuntimeException('factory failed');
                }
            },
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('factory failed');

        $easy->createResponse();
    }

    public function testCreateResponseRejectsInvalidResponseFactory(): void
    {
        $easy = self::createEasyHandle();
        $easy->sink = Psr7\Utils::streamFor('');
        $easy->headers = ['HTTP/1.1 200 OK'];
        $easy->options = [RequestOptions::RESPONSE_FACTORY => new \stdClass()];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('response_factory must be an instance of Psr\\Http\\Message\\ResponseFactoryInterface');

        $easy->createResponse();
    }

    private static function createEasyHandle(): EasyHandle
    {
        $easy = new EasyHandle();
        $easy->request = new Psr7\Request('GET', 'http://example.com');

        return $easy;
    }
}
