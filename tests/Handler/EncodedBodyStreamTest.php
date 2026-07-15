<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\EncodedBodyStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\EncodedBodyStream
 */
class EncodedBodyStreamTest extends TestCase
{
    public function testLimitsAndCountsEncodedBodyReads(): void
    {
        $source = Utils::streamFor('abcdef');
        $stream = new EncodedBodyStream($source, '3');

        self::assertSame('ab', $stream->read(2));
        self::assertSame('c', $stream->read(2));
        self::assertSame('3', $stream->getDeclaredLength());
        self::assertSame(3, $stream->getBytesRead());
        self::assertSame(3, $source->tell());
        self::assertFalse($stream->isSeekable());
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot seek a stream while tracking encoded response bytes');
        $stream->rewind();
    }
}
