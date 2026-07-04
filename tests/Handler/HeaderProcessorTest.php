<?php

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

    public static function statusLineCandidateProvider(): iterable
    {
        yield 'http/1.1' => ['HTTP/1.1 200 OK', true];
        yield 'http/2 without reason' => ['HTTP/2 200', true];
        yield 'http/2 with trailing space' => ['HTTP/2 200 ', true];
        yield 'lowercase protocol' => ['http/1.1 204 No Content', true];
        yield 'status-shaped out of range' => ['HTTP/1.1 999 Weird', true];
        yield 'leading whitespace' => [' HTTP/1.1 200 OK', false];
        yield 'tab between version and status' => ["HTTP/1.1\t200 OK", false];
        yield 'multiple spaces before status' => ['HTTP/1.1  200 OK', false];
        yield 'multiple spaces before reason' => ['HTTP/1.1 200  OK', true];
        yield 'tab before reason' => ["HTTP/1.1 200\tOK", false];
        yield 'non-numeric version' => ['HTTP/foo 200 OK', false];
        yield 'missing status' => ['HTTP/1.1', false];
        yield 'non-numeric status' => ['HTTP/1.1 OK', false];
        yield 'short status' => ['HTTP/1.1 20 OK', false];
        yield 'status suffix' => ['HTTP/1.1 200abc Weird', false];
    }

    /**
     * @dataProvider statusLineCandidateProvider
     */
    public function testIdentifiesStatusLineCandidates(string $line, bool $expected): void
    {
        self::assertSame($expected, HeaderProcessor::isStatusLineCandidate($line));
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

    public function testRejectsMalformedStatusCode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code is invalid');

        HeaderProcessor::parseHeaders([
            'HTTP/1.1 200abc Weird',
        ]);
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
