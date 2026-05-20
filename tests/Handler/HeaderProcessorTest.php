<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\HeaderProcessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\HeaderProcessor
 */
class HeaderProcessorTest extends TestCase
{
    public function testParsesLastHeaderBlock(): void
    {
        [$version, $status, $reason, $headers] = HeaderProcessor::parseHeaders([
            'HTTP/1.1 100 Continue',
            'Ignored: header',
            'HTTP/1.1 200 OK',
            'X-Foo: bar',
            'X-Foo: baz',
            'X-Bar: qux',
        ]);

        self::assertSame('1.1', $version);
        self::assertSame(200, $status);
        self::assertSame('OK', $reason);
        self::assertSame(['X-Foo' => ['bar', 'baz'], 'X-Bar' => ['qux']], $headers);
    }

    public function testRejectsEmptyHeaderData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected a non-empty array of header data');

        HeaderProcessor::parseHeaders([]);
    }

    public function testRejectsMissingStatusCode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code missing from header data');

        HeaderProcessor::parseHeaders([
            'HTTP/1.1',
        ]);
    }

    public function testParsesBoundaryStatusCodes(): void
    {
        [, $informationalStatus] = HeaderProcessor::parseHeaders(['HTTP/1.1 100 Continue']);
        [, $customServerErrorStatus] = HeaderProcessor::parseHeaders(['HTTP/1.1 599 Custom']);

        self::assertSame(100, $informationalStatus);
        self::assertSame(599, $customServerErrorStatus);
    }

    public function testRejectsMalformedStatusCode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code is invalid');

        HeaderProcessor::parseHeaders([
            'HTTP/1.1 200abc Weird',
        ]);
    }

    /**
     * @dataProvider invalidStatusCodeProvider
     */
    public function testRejectsOutOfRangeStatusCode(string $statusLine): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code is invalid');

        HeaderProcessor::parseHeaders([$statusLine]);
    }

    public static function invalidStatusCodeProvider(): iterable
    {
        return [
            ['HTTP/1.1 099 Bad'],
            ['HTTP/1.1 600 Bad'],
            ['HTTP/1.1 700 Bad'],
            ['HTTP/1.1 999 Bad'],
        ];
    }

    public function testRejectsMalformedHeaderLine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP header line is invalid');

        HeaderProcessor::parseHeaders([
            'HTTP/1.1 200 OK',
            'X-Foo',
        ]);
    }
}
