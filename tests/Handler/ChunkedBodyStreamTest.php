<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\ChunkedBodyStream;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\ChunkedBodyStream
 */
class ChunkedBodyStreamTest extends TestCase
{
    /**
     * @dataProvider chunkedBodyProvider
     */
    public function testDecodesBodyHoweverTheTransportSplitsIt(string $wire, string $expected): void
    {
        foreach ([\strlen($wire), 3, 1] as $pieceLength) {
            foreach ([5, 8192] as $readLength) {
                $stream = new ChunkedBodyStream(self::transport(\str_split($wire, $pieceLength)));
                $body = '';
                while (!$stream->eof()) {
                    $body .= $stream->read($readLength);
                }

                self::assertSame($expected, $body);
                self::assertSame(\strlen($expected), $stream->tell());
            }
        }
    }

    public static function chunkedBodyProvider(): iterable
    {
        yield 'several chunks' => ["3\r\nabc\r\nA\r\n0123456789\r\n1\r\nd\r\n0\r\n\r\n", 'abc0123456789d'];
        yield 'many small chunks' => [\str_repeat("1\r\na\r\n2\nbc\n", 1000)."0\r\n\r\n", \str_repeat('abc', 1000)];
        yield 'zero padded chunk sizes' => ["00000003\r\nabc\r\n0000000000000000A\r\n0123456789\r\n0\r\n\r\n", 'abc0123456789'];
        yield 'chunk extensions' => ["3;name=\"a;b\"\r\nabc\r\n0;last\r\n\r\n", 'abc'];
        yield 'bare LF line endings' => ["3\nabc\n0\n\n", 'abc'];
        yield 'repeated CR after chunk data' => ["3\r\nabc\r\r\n1\r\nd\r\n0\r\n\r\n", 'abcd'];
        yield 'trailer fields' => ["3\r\nabc\r\n0\r\nExpires: never\r\nX-Checksum: 1\r\n\r\n", 'abc'];
    }

    public function testDecodesEveryBufferedChunkInOneRead(): void
    {
        $reads = 0;
        $stream = new ChunkedBodyStream(self::transport(["1\r\na\r\n1\r\nb\r\n1\r\nc\r\n0\r\n\r\n"], $reads));

        self::assertSame('abc', $stream->read(8192));
        self::assertTrue($stream->eof());
        self::assertSame(1, $reads);
    }

    public function testEndsAtLastChunkWithoutReadingFurther(): void
    {
        $reads = 0;
        $stream = new ChunkedBodyStream(self::transport(["3\r\nabc\r\n0\r\nX-Checksum: 1\r\n\r\n"], $reads));

        self::assertSame('abc', $stream->read(3));
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(8192));
        self::assertSame(1, $reads);
    }

    public function testResumesAfterTransportTimeoutInEveryState(): void
    {
        $wire = "3;x\r\nabc\r\n0\r\nX-Checksum: 1\r\n\r\n";
        $pieces = [];
        foreach (\str_split($wire) as $byte) {
            $pieces[] = null;
            $pieces[] = $byte;
        }
        $stream = new ChunkedBodyStream(self::transport($pieces));
        $body = '';
        $timeouts = 0;
        while (!$stream->eof()) {
            try {
                $body .= $stream->read(8192);
            } catch (TimeoutException $e) {
                ++$timeouts;
            }
        }

        self::assertSame('abc', $body);
        self::assertSame(\strlen($wire), $timeouts);
    }

    public function testReturnsDecodedBytesBeforeFramingFailure(): void
    {
        $stream = new ChunkedBodyStream(self::transport(["3\r\nabcX\r\n0\r\n\r\n"]));

        self::assertSame('abc', $stream->read(8192));
        self::assertFalse($stream->eof());
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $stream->read(8192);
                self::fail('Expected the framing failure');
            } catch (\RuntimeException $e) {
                self::assertSame('Invalid chunked response body: missing CRLF after chunk data', $e->getMessage());
            }
        }
    }

    /**
     * @dataProvider failingBodyProvider
     */
    public function testReportsFailureRatherThanStaleTimeout(string $wire, string $message): void
    {
        $stream = new ChunkedBodyStream(self::transport([$wire], $reads, true));

        try {
            Utils::copyToString($stream);
            self::fail('Expected the body failure');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame($message, $e->getMessage());
        }
        self::assertFalse($stream->eof());
        self::assertFalse($stream->getMetadata('timed_out'));
        self::assertFalse($stream->getMetadata()['timed_out']);
    }

    public static function failingBodyProvider(): iterable
    {
        yield 'malformed' => ["3\r\nabcX\r\n0\r\n\r\n", 'Invalid chunked response body: missing CRLF after chunk data'];
        yield 'truncated' => ["3\r\nab", 'Response body ended before the chunked body was complete'];
    }

    public function testDetachesUnreadBodyWithPhpDecoding(): void
    {
        $resource = Utils::tryFopen('php://temp', 'r+');
        \fwrite($resource, "3\r\nabc\r\n0\r\n\r\n");
        \rewind($resource);
        $stream = new ChunkedBodyStream(Utils::streamFor($resource));

        $detached = $stream->detach();

        self::assertSame($resource, $detached);
        self::assertSame('abc', \stream_get_contents($detached));
        \fclose($detached);
    }

    public function testDetachesCompletedBodyTransport(): void
    {
        $resource = Utils::tryFopen('php://temp', 'r+');
        \fwrite($resource, "3\r\nabc\r\n0\r\n\r\n");
        \rewind($resource);
        $stream = new ChunkedBodyStream(Utils::streamFor($resource));
        self::assertSame('abc', $stream->getContents());

        $detached = $stream->detach();

        self::assertSame($resource, $detached);
        \fclose($detached);
    }

    public function testRejectsDetachingPartiallyReadBody(): void
    {
        $stream = new ChunkedBodyStream(self::transport(["3\r\nabc\r\n", "0\r\n\r\n"]));
        self::assertSame('ab', $stream->read(2));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot detach a chunked response body after reading from it');
        $stream->detach();
    }

    /**
     * @param array<string|null> $pieces   Transport reads in order, null for a timeout
     * @param bool               $timedOut Whether the transport reports a stale timed_out flag
     */
    private static function transport(array $pieces, ?int &$reads = null, bool $timedOut = false): FnStream
    {
        $reads = 0;

        return FnStream::decorate(Utils::streamFor(''), [
            'read' => static function (int $length) use (&$pieces, &$reads): string {
                ++$reads;
                if ($pieces === []) {
                    return '';
                }

                $piece = \array_shift($pieces);
                if ($piece === null) {
                    throw new TimeoutException('Unable to read from stream: timed out');
                }

                return $piece;
            },
            'eof' => static function () use (&$pieces): bool {
                return $pieces === [];
            },
            'getMetadata' => static function (?string $key = null) use ($timedOut) {
                if ($key === null) {
                    return ['timed_out' => $timedOut];
                }

                return $key === 'timed_out' ? $timedOut : null;
            },
        ]);
    }
}
