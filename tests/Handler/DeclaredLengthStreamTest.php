<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\DeclaredLengthStream;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\DeclaredLengthStream
 */
class DeclaredLengthStreamTest extends TestCase
{
    public function testEndsAtDeclaredLength(): void
    {
        $source = Utils::streamFor('abcdef');
        $stream = new DeclaredLengthStream($source, 3);

        self::assertSame('ab', $stream->read(2));
        self::assertSame('c', $stream->read(2));
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(1));
        self::assertSame(3, $source->tell());
        self::assertFalse($stream->isSeekable());
    }

    public function testZeroDeclaredLengthNeverReadsTheSource(): void
    {
        $stream = new DeclaredLengthStream(new FnStream([
            'read' => static function (): string {
                throw new \LogicException('The source must not be read');
            },
        ]), 0);

        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(8192));
    }

    public function testShortSourceFailsEveryLaterRead(): void
    {
        $stream = new DeclaredLengthStream(Utils::streamFor('abc'), 5);

        self::assertSame('abc', $stream->read(8192));
        self::assertFalse($stream->eof());

        try {
            $stream->read(8192);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('Response body ended before the declared Content-Length was reached', $e->getMessage());
        }

        self::assertTrue($stream->eof());
        $this->expectExceptionObject($e);
        $stream->read(8192);
    }

    public function testShortSourceFailureIsNotReportedAsTimeout(): void
    {
        $source = new FnStream([
            'read' => static function (): string {
                return '';
            },
            'eof' => static function (): bool {
                return true;
            },
            'getMetadata' => static function (?string $key = null) {
                return $key === 'timed_out' ? true : null;
            },
        ]);
        $stream = new DeclaredLengthStream($source, 5);

        try {
            Utils::copyToStream($stream, Utils::streamFor());
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame('Response body ended before the declared Content-Length was reached', $e->getMessage());
        }
    }

    public function testSourceTimeoutCanBeRetried(): void
    {
        $reads = ['ab', null, 'cd'];
        $stream = new DeclaredLengthStream(new FnStream([
            'read' => static function () use (&$reads): string {
                $data = \array_shift($reads);
                if ($data === null) {
                    throw new TimeoutException('Unable to read from stream: timed out');
                }

                return $data;
            },
            'eof' => static function () use (&$reads): bool {
                return $reads === [];
            },
        ]), 4);

        self::assertSame('ab', $stream->read(4));

        try {
            $stream->read(4);
            self::fail('Expected TimeoutException');
        } catch (TimeoutException $e) {
            self::assertFalse($stream->eof());
        }

        self::assertSame('cd', $stream->read(4));
        self::assertTrue($stream->eof());
    }
}
