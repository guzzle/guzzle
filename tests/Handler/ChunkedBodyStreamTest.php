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
        foreach ([1, 2, 3, 7, \strlen($wire)] as $pieceLength) {
            $stream = new ChunkedBodyStream(self::transport(\str_split($wire, $pieceLength)));
            $body = '';
            while (!$stream->eof()) {
                $body .= $stream->read(5);
            }

            self::assertSame($expected, $body);
            self::assertSame(\strlen($expected), $stream->tell());
        }
    }

    public static function chunkedBodyProvider(): iterable
    {
        yield 'single chunk' => ["3\r\nabc\r\n0\r\n\r\n", 'abc'];
        yield 'several chunks' => ["3\r\nabc\r\nA\r\n0123456789\r\n1\r\nd\r\n0\r\n\r\n", 'abc0123456789d'];
        yield 'uppercase and padded size' => ["000B\r\nhello world\r\n0\r\n\r\n", 'hello world'];
        yield 'chunk extensions' => ["3;name=value\r\nabc\r\n0;last\r\n\r\n", 'abc'];
        yield 'trailers' => ["3\r\nabc\r\n0\r\nExpires: never\r\nX-Checksum: 1\r\n\r\n", 'abc'];
        yield 'empty body' => ["0\r\n\r\n", ''];
    }

    public function testResumesAfterTransportTimeout(): void
    {
        $pieces = ["3\r\na", null, "bc\r\n", null, "0\r\n\r\n"];
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
        self::assertSame(2, $timeouts);
    }

    /**
     * @param array<string|null> $pieces Transport reads, null for a timeout
     */
    private static function transport(array $pieces): FnStream
    {
        return FnStream::decorate(Utils::streamFor(''), [
            'read' => static function (int $length) use (&$pieces): string {
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
            'getMetadata' => static function (?string $key = null) {
                return $key === null ? [] : null;
            },
        ]);
    }
}
