<?php

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\HeaderProcessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\HeaderProcessor
 */
class HeaderProcessorTest extends TestCase
{
    public function testThrowsOnEmptyHeaders(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected a non-empty array of header data');
        HeaderProcessor::parseHeaders([]);
    }

    public function testThrowsWhenVersionMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP version missing from header data');
        HeaderProcessor::parseHeaders(['INVALID 200 OK']);
    }

    public function testThrowsWhenStatusCodeMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code missing from header data');
        HeaderProcessor::parseHeaders(['HTTP/1.1']);
    }

    public function testParsesHttp11Response(): void
    {
        [$version, $status, $reason, $headers] = HeaderProcessor::parseHeaders([
            'HTTP/1.1 200 OK',
            'Content-Type: text/plain',
        ]);

        self::assertSame('1.1', $version);
        self::assertSame(200, $status);
        self::assertSame('OK', $reason);
        self::assertSame(['Content-Type' => ['text/plain']], $headers);
    }

    public function testParsesHttp2Response(): void
    {
        [$version, $status, $reason, $headers] = HeaderProcessor::parseHeaders([
            'HTTP/2 204 No Content',
        ]);

        self::assertSame('2', $version);
        self::assertSame(204, $status);
        self::assertSame('No Content', $reason);
        self::assertSame([], $headers);
    }

    public function testReasonPhraseIsNullWhenAbsent(): void
    {
        [$version, $status, $reason] = HeaderProcessor::parseHeaders(['HTTP/1.1 200']);

        self::assertSame('1.1', $version);
        self::assertSame(200, $status);
        self::assertNull($reason);
    }

    public function testParsesMultipleHeaders(): void
    {
        [$version, $status, $reason, $headers] = HeaderProcessor::parseHeaders([
            'HTTP/1.1 200 OK',
            'Content-Type: application/json',
            'X-Custom: foo',
            'X-Custom: bar',
        ]);

        self::assertSame('1.1', $version);
        self::assertSame(200, $status);
        self::assertSame('OK', $reason);
        self::assertSame(['application/json'], $headers['Content-Type']);
        self::assertSame(['foo', 'bar'], $headers['X-Custom']);
    }
}
