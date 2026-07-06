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

    public static function trailerFieldLineProvider(): iterable
    {
        yield 'simple' => ['X-Checksum: abc', true];
        yield 'empty value' => ['X-Empty:', true];
        yield 'colon in value' => ['X-Value: a:b:c', true];
        yield 'ows value' => ["X-Value: \t abc \t", true];
        yield 'token chars' => ["!#$%&'*+.^_`|~-0123456789: ok", true];
        yield 'obs-text value' => ["X-Obs: \x80\xFF", true];
        yield 'semantic content-length' => ['Content-Length: 123', true];
        yield 'semantic transfer-encoding' => ['Transfer-Encoding: chunked', true];
        yield 'missing colon' => ['X-Checksum', false];
        yield 'empty name' => [': pseudo', false];
        yield 'leading whitespace' => [' X-Name: value', false];
        yield 'space in name' => ['Bad Name: value', false];
        yield 'tab in name' => ["Bad\tName: value", false];
        yield 'slash in name' => ['Bad/Name: value', false];
        yield 'control in name' => ["Bad\x01Name: value", false];
        yield 'nul in value' => ["Bad: value\x00", false];
        yield 'cr in value' => ["Bad: value\rmore", false];
        yield 'lf in value' => ["Bad: value\nmore", false];
        yield 'del in value' => ["Bad: value\x7F", false];
    }

    /**
     * @dataProvider trailerFieldLineProvider
     */
    public function testValidatesTrailerFieldLines(string $line, bool $expected): void
    {
        self::assertSame($expected, HeaderProcessor::isValidHeaderFieldLine($line));
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

    public function testRejectsStatusCodeWithTrailingNewline(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code is invalid');

        HeaderProcessor::parseHeaders([
            "HTTP/1.1 200\n",
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
