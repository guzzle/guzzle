<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\InflateBodyStream;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/**
 * @covers \GuzzleHttp\Handler\InflateBodyStream
 */
class InflateBodyStreamTest extends TestCase
{
    public function testReturnsDecodedBytesWithoutWaitingForMoreEncodedBytes(): void
    {
        $context = \deflate_init(\ZLIB_ENCODING_GZIP);
        $first = (string) \deflate_add($context, 'first', \ZLIB_SYNC_FLUSH);
        $second = (string) \deflate_add($context, 'second', \ZLIB_FINISH);
        $stream = new InflateBodyStream(self::pieces([\substr($first, 0, 1), \substr($first, 1), $second], $reads));

        self::assertSame('first', $stream->read(8192));
        self::assertSame(2, $reads);
        self::assertSame(5, $stream->tell());
        self::assertSame('second', $stream->read(8192));
        self::assertSame('', $stream->read(8192));
        self::assertTrue($stream->eof());
    }

    /**
     * @dataProvider encodedBodyProvider
     */
    public function testDecodesFirstCompressedStream(string $encoded, string $decoded): void
    {
        $source = Utils::streamFor($encoded);
        $stream = new InflateBodyStream($source);

        self::assertSame($decoded, (string) $stream);
        self::assertTrue($stream->eof());
        self::assertTrue($source->eof());
    }

    public static function encodedBodyProvider(): iterable
    {
        $decoded = \str_repeat('payload-', 2000);

        yield 'gzip' => [(string) \gzencode($decoded), $decoded];
        yield 'zlib' => [(string) \gzcompress($decoded), $decoded];
        yield 'empty' => ['', ''];
        yield 'second gzip member' => [\gzencode($decoded).\gzencode('second'), $decoded];
        yield 'trailing bytes' => [\gzencode($decoded).'trailing', $decoded];
        yield 'truncated trailer' => [\substr((string) \gzencode($decoded), 0, -8), $decoded];
    }

    /**
     * @dataProvider invalidEncodedBodyProvider
     */
    public function testRejectsInvalidCompressedData(string $encoded): void
    {
        $stream = new InflateBodyStream(Utils::streamFor($encoded));
        $errors = [];
        \set_error_handler(static function (int $level, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $stream->getContents();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('Unable to decode the response body: invalid gzip or deflate data', $e->getMessage());
        } finally {
            \restore_error_handler();
        }

        self::assertSame([], $errors);
        self::assertFalse($stream->eof());

        try {
            $stream->read(1);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $again) {
            self::assertSame($e, $again);
        }
    }

    public static function invalidEncodedBodyProvider(): iterable
    {
        $decoded = \str_repeat('payload-', 2000);
        $gzip = (string) \gzencode($decoded);
        $badChecksum = $gzip;
        $badChecksum[\strlen($gzip) - 8] = \chr(\ord($gzip[\strlen($gzip) - 8]) ^ 0xFF);

        yield 'raw deflate' => [(string) \gzdeflate($decoded)];
        yield 'not compressed' => [$decoded];
        yield 'bad checksum' => [$badChecksum];
    }

    public function testDecodingFailureIsNotReportedAsTimeout(): void
    {
        $source = FnStream::decorate(self::pieces(['not compressed', 'more']), [
            'getMetadata' => static function (?string $key = null) {
                $metadata = ['timed_out' => true];

                return $key === null ? $metadata : $metadata[$key] ?? null;
            },
        ]);
        $stream = new InflateBodyStream($source);

        try {
            $stream->getContents();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame('Unable to decode the response body: invalid gzip or deflate data', $e->getMessage());
        }

        self::assertFalse($stream->getMetadata('timed_out'));
        self::assertFalse($stream->getMetadata()['timed_out']);
    }

    public function testResourceReportsDecodingFailureInsteadOfEndingCleanly(): void
    {
        $resource = StreamWrapper::getResource(new InflateBodyStream(Utils::streamFor('not compressed')));

        self::assertFalse(@\fread($resource, 8192));
        self::assertFalse(\feof($resource));
    }

    /**
     * @dataProvider sourceExceptionProvider
     */
    public function testPropagatesSourceExceptionsUnchanged(\RuntimeException $exception): void
    {
        $gzip = (string) \gzencode('decoded');
        $stream = new InflateBodyStream(self::pieces([\substr($gzip, 0, 1), $exception, \substr($gzip, 1)]));

        try {
            $stream->read(8192);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame($exception, $e);
        }

        self::assertSame('decoded', $stream->getContents());
    }

    public static function sourceExceptionProvider(): iterable
    {
        yield 'timeout' => [new TimeoutException('Unable to read from stream: timed out')];
        yield 'framing' => [new \RuntimeException('Chunked response body ended before the final chunk')];
    }

    public function testDetachReturnsResourceYieldingDecodedBytes(): void
    {
        $stream = new InflateBodyStream(Utils::streamFor(\gzencode('decoded')));
        self::assertSame('de', $stream->read(2));

        $resource = $stream->detach();

        self::assertIsResource($resource);
        self::assertSame('coded', \stream_get_contents($resource));
        self::assertNull($stream->detach());
        self::assertFalse($stream->isReadable());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');
        $stream->read(1);
    }

    public function testDetachedResourceCannotBeCastToADescriptor(): void
    {
        $resource = (new InflateBodyStream(Utils::streamFor(\gzencode('decoded'))))->detach();
        $wrapper = \stream_get_meta_data($resource)['wrapper_data'];

        self::assertInstanceOf(StreamWrapper::class, $wrapper);
        self::assertFalse($wrapper->stream_cast(\STREAM_CAST_FOR_SELECT));
        self::assertSame('decoded', \stream_get_contents($resource));
    }

    public function testIsReadOnlyAndNotSeekable(): void
    {
        $stream = new InflateBodyStream(Utils::streamFor(\gzencode('decoded')));

        self::assertNull($stream->getSize());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stream is not seekable');
        $stream->rewind();
    }

    /**
     * @param list<string|\Throwable> $pieces
     */
    private static function pieces(array $pieces, ?int &$reads = null): StreamInterface
    {
        $reads = 0;

        return new FnStream([
            'read' => static function () use (&$pieces, &$reads): string {
                ++$reads;
                $piece = \array_shift($pieces) ?? '';
                if ($piece instanceof \Throwable) {
                    throw $piece;
                }

                return $piece;
            },
            'eof' => static function () use (&$pieces): bool {
                return $pieces === [];
            },
            'isReadable' => static function (): bool {
                return true;
            },
            'isWritable' => static function (): bool {
                return false;
            },
            'isSeekable' => static function (): bool {
                return false;
            },
        ]);
    }
}
