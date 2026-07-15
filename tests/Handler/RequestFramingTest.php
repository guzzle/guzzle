<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\RequestFraming;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\RequestFraming
 */
class RequestFramingTest extends TestCase
{
    public static function acceptedFramingProvider(): iterable
    {
        yield 'known body' => [[], '1.1', '3'];
        yield 'equivalent content lengths' => [
            ['Content-Length' => ['0003', '3']],
            '1.1',
            '3',
        ];
        yield 'equivalent comma content lengths' => [
            ['Content-Length' => '0003, 3'],
            '1.1',
            '3',
        ];
        yield 'known body with provisional chunked' => [
            ['Transfer-Encoding' => 'ChUnKeD'],
            '1.1',
            '3',
        ];
    }

    /**
     * @dataProvider acceptedFramingProvider
     *
     * @param array<string, string|string[]> $headers
     */
    public function testAnalyzesAcceptedFraming(array $headers, string $protocol, string $expectedLength): void
    {
        $framing = RequestFraming::analyze(new Psr7\Request('PUT', 'https://example.com', $headers, 'abc', $protocol));

        self::assertSame(3, $framing->bodySize);
        self::assertSame(3, $framing->contentLength);
        self::assertSame([$expectedLength], $framing->request->getHeader('Content-Length'));
        self::assertFalse($framing->request->hasHeader('Transfer-Encoding'));
    }

    public static function rejectedFramingProvider(): iterable
    {
        yield 'malformed content length' => [
            ['Content-Length' => '3x'],
            'abc',
            '1.1',
            'Invalid Content-Length request header',
        ];
        yield 'content length and transfer encoding' => [
            ['Content-Length' => '3', 'Transfer-Encoding' => 'chunked'],
            'abc',
            '1.1',
            'must not contain both Content-Length and Transfer-Encoding',
        ];
        yield 'zero content length and transfer encoding' => [
            ['Content-Length' => '0', 'Transfer-Encoding' => 'chunked'],
            '',
            '1.1',
            'must not contain both Content-Length and Transfer-Encoding',
        ];
        yield 'known length mismatch' => [
            ['Content-Length' => '2'],
            'abc',
            '1.1',
            'Content-Length does not match the request body size',
        ];
        yield 'declared length longer than known body' => [
            ['Content-Length' => '4'],
            'abc',
            '1.1',
            'Content-Length does not match the request body size',
        ];
        yield 'unsupported transfer coding' => [
            ['Transfer-Encoding' => 'gzip'],
            'abc',
            '1.1',
            'Unsupported Transfer-Encoding request header',
        ];
        yield 'transfer coding chain' => [
            ['Transfer-Encoding' => 'gzip, chunked'],
            'abc',
            '1.1',
            'Unsupported Transfer-Encoding request header',
        ];
        yield 'repeated chunked' => [
            ['Transfer-Encoding' => ['chunked', 'chunked']],
            'abc',
            '1.1',
            'Unsupported Transfer-Encoding request header',
        ];
        yield 'chunked on HTTP/2' => [
            ['Transfer-Encoding' => 'chunked'],
            'abc',
            '2',
            'Unsupported Transfer-Encoding request header',
        ];
    }

    /**
     * @dataProvider rejectedFramingProvider
     *
     * @param array<string, string|string[]> $headers
     */
    public function testRejectsInvalidFraming(array $headers, string $body, string $protocol, string $message): void
    {
        $request = new Psr7\Request('PUT', 'https://example.com', $headers, $body, $protocol);

        try {
            RequestFraming::analyze($request);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertEquals($request, $e->getRequest());
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testRejectsUnknownSizeHttp10BodyWithoutContentLength(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $request = new Psr7\Request('PUT', 'https://example.com', [], $body, '1.0');

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An unknown-size HTTP/1.0 request body requires Content-Length');

        RequestFraming::analyze($request);
    }

    public static function emptyBodyMethodProvider(): iterable
    {
        yield 'GET' => ['GET', false];
        yield 'POST' => ['POST', true];
        yield 'PUT' => ['PUT', true];
    }

    /**
     * @dataProvider emptyBodyMethodProvider
     */
    public function testAddsZeroContentLengthOnlyForEmptyPutAndPost(string $method, bool $hasLength): void
    {
        $framing = RequestFraming::analyze(new Psr7\Request($method, 'https://example.com'));

        self::assertSame($hasLength, $framing->request->hasHeader('Content-Length'));
        self::assertSame($hasLength ? 0 : null, $framing->contentLength);
    }

    public function testAcceptsUnknownBodyWithMixedCaseChunked(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $framing = RequestFraming::analyze(new Psr7\Request(
            'PUT',
            'https://example.com',
            ['Transfer-Encoding' => 'ChUnKeD'],
            $body
        ));

        self::assertNull($framing->bodySize);
        self::assertNull($framing->contentLength);
        self::assertFalse($framing->request->hasHeader('Transfer-Encoding'));
    }

    public function testRejectsUnrepresentableContentLength(): void
    {
        $request = new Psr7\Request('PUT', 'https://example.com', [
            'Content-Length' => ((string) \PHP_INT_MAX).'0',
        ]);

        try {
            RequestFraming::analyze($request);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame(
                'Content-Length exceeds the maximum integer size supported on this platform',
                $e->getMessage()
            );
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(\OverflowException::class, $e->getPrevious());
        }
    }

    public function testCanonicalizesContentLengthBeforeInspectingBodySize(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): ?int {
                throw new \RuntimeException('Cannot determine size');
            },
        ]);
        $request = new Psr7\Request('PUT', 'https://example.com', [
            'Content-Length' => '0003, 3',
        ], $body);

        try {
            RequestFraming::analyze($request);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('Cannot determine size', $e->getMessage());
            self::assertSame(['3'], $e->getRequest()->getHeader('Content-Length'));
        }
    }

    public function testUsesRemainingSizeForPositionedNonSeekableBody(): void
    {
        $body = Psr7\Utils::streamFor('abcdef');
        $body->read(2);
        $request = new Psr7\Request('PUT', 'https://example.com', [], new Psr7\NoSeekStream($body));

        $framing = RequestFraming::analyze($request);

        self::assertSame(4, $framing->bodySize);
        self::assertSame('4', $framing->request->getHeaderLine('Content-Length'));
        self::assertSame('cdef', $framing->materialize());
    }

    public function testUsesTotalSizeForPositionedSeekableBody(): void
    {
        $body = Psr7\Utils::streamFor('abcdef');
        $body->read(2);
        $request = new Psr7\Request('PUT', 'https://example.com', [], $body);

        $framing = RequestFraming::analyze($request);

        self::assertSame(6, $framing->bodySize);
        self::assertSame('6', $framing->request->getHeaderLine('Content-Length'));
    }

    public function testTreatsFailedNonSeekableTellAsUnknownSize(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'isSeekable' => static function (): bool {
                return false;
            },
            'tell' => static function (): int {
                throw new \RuntimeException('position unavailable');
            },
        ]);

        self::assertNull(RequestFraming::bodySize(new Psr7\Request('PUT', 'https://example.com', [], $body)));
    }

    public static function invalidBodyPositionProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'past end' => [4];
    }

    /**
     * @dataProvider invalidBodyPositionProvider
     */
    public function testRejectsPositionOutsideKnownNonSeekableBody(int $position): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'isSeekable' => static function (): bool {
                return false;
            },
            'tell' => static function () use ($position): int {
                return $position;
            },
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The request body position is outside the stream size');

        RequestFraming::bodySize(new Psr7\Request('PUT', 'https://example.com', [], $body));
    }

    public function testRejectsNegativeBodySize(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): ?int {
                return -1;
            },
        ]);
        $request = new Psr7\Request('PUT', 'https://example.com', [], $body);

        try {
            RequestFraming::bodySize($request);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('Request body size must not be negative', $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testWrapsSeekabilityFailureWhileDeterminingBodySize(): void
    {
        $previous = new \RuntimeException('Cannot determine seekability');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'isSeekable' => static function () use ($previous): bool {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', 'https://example.com', [], $body);

        try {
            RequestFraming::bodySize($request);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('Cannot determine seekability', $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testHeadCanonicalizesLengthAndStripsTransferEncodingWithoutProbingBody(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): ?int {
                throw new \Error('body must not be probed');
            },
        ]);
        $request = new Psr7\Request('HEAD', 'https://example.com', [
            'Content-Length' => ['0003', '3'],
            'Transfer-Encoding' => 'gzip',
        ], $body);

        $framing = RequestFraming::analyze($request, false);

        self::assertSame(['3'], $framing->request->getHeader('Content-Length'));
        self::assertFalse($framing->request->hasHeader('Transfer-Encoding'));
        self::assertNull($framing->bodySize);
        self::assertSame(3, $framing->contentLength);
    }

    public function testMaterializesOnlyDeclaredBoundary(): void
    {
        $source = Psr7\Utils::streamFor('abcdef');
        $body = Psr7\FnStream::decorate($source, [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $framing = RequestFraming::analyze(new Psr7\Request(
            'PUT',
            'https://example.com',
            ['Content-Length' => '3'],
            $body
        ));

        self::assertSame('abc', $framing->materialize());
        self::assertSame('def', $source->getContents());
    }

    public function testRejectsPrematureEndWhileMaterializing(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('ab'), [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $framing = RequestFraming::analyze(new Psr7\Request(
            'PUT',
            'https://example.com',
            ['Content-Length' => '3'],
            $body
        ));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Request body ended before the declared Content-Length was reached');

        $framing->materialize();
    }

    public function testRejectsStreamReturningMoreThanRequested(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
            'getSize' => static function (): ?int {
                return null;
            },
            'isSeekable' => static function (): bool {
                return false;
            },
            'read' => static function (): string {
                return 'abcd';
            },
        ]);
        $framing = RequestFraming::analyze(new Psr7\Request(
            'PUT',
            'https://example.com',
            ['Content-Length' => '3'],
            $body
        ));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Request body stream returned more bytes than requested');

        $framing->materialize();
    }

    public function testZeroBoundaryDoesNotTouchBody(): void
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'isSeekable' => static function (): bool {
                self::fail('The body must not be inspected past its unknown size');
            },
            'rewind' => static function (): void {
                self::fail('The body must not be rewound');
            },
            'read' => static function (): string {
                self::fail('The body must not be read');
            },
        ]);
        $framing = RequestFraming::analyze(new Psr7\Request(
            'PUT',
            'https://example.com',
            ['Content-Length' => '0'],
            $body
        ));

        self::assertSame('', $framing->materialize());
    }

    public function testCannotBeSerialized(): void
    {
        $framing = RequestFraming::analyze(new Psr7\Request('PUT', 'https://example.com', [], 'abc'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RequestFraming::class.' should never be serialized');

        \serialize($framing);
    }
}
