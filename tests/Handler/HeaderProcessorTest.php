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

    /**
     * @dataProvider validContentLengthProvider
     *
     * @param string[] $values
     */
    public function testParsesContentLength(array $values, ?string $expected): void
    {
        self::assertSame($expected, HeaderProcessor::parseContentLength($values));
    }

    public static function validContentLengthProvider(): iterable
    {
        $max = (string) \PHP_INT_MAX;

        return [
            'absent' => [[], null],
            'zero' => [['0'], '0'],
            'zero with leading zeros' => [['0000'], '0'],
            'simple' => [['3'], '3'],
            'leading zeros' => [['0003'], '3'],
            'comma equivalent' => [['003, 3'], '3'],
            'duplicate equivalent' => [['003', '3'], '3'],
            'php int max' => [[$max], $max],
            'larger than php int max' => [[$max.'0'], $max.'0'],
        ];
    }

    /**
     * @dataProvider validResponseFramingProvider
     *
     * @param array<string, string[]> $headers
     */
    public function testValidatesResponseFraming(
        string $method,
        int $status,
        array $headers,
        ?string $expected
    ): void {
        self::assertSame($expected, HeaderProcessor::validateResponseFraming($method, $status, $headers));
    }

    public static function validResponseFramingProvider(): iterable
    {
        yield 'absent' => ['GET', 200, [], null];
        yield 'valid' => ['GET', 200, ['Content-Length' => ['003']], '3'];
        yield 'mixed case' => ['GET', 200, ['cOnTeNt-LeNgTh' => ['003']], '3'];
        yield 'equivalent mixed-case duplicates' => [
            'GET',
            200,
            ['Content-Length' => ['003'], 'content-length' => ['3']],
            '3',
        ];
        yield 'transfer encoding only' => ['GET', 200, ['Transfer-Encoding' => ['gzip, chunked']], null];
        yield 'head' => [
            'HEAD',
            200,
            ['Content-Length' => ['bad'], 'Transfer-Encoding' => ['chunked']],
            null,
        ];
        yield 'informational' => [
            'GET',
            101,
            ['Content-Length' => ['bad'], 'Transfer-Encoding' => ['chunked']],
            null,
        ];
        yield 'no content' => [
            'GET',
            204,
            ['Content-Length' => ['bad'], 'Transfer-Encoding' => ['chunked']],
            null,
        ];
        yield 'not modified' => [
            'GET',
            304,
            ['Content-Length' => ['bad'], 'Transfer-Encoding' => ['chunked']],
            null,
        ];
        yield 'successful connect' => [
            'CONNECT',
            200,
            ['Content-Length' => ['bad'], 'Transfer-Encoding' => ['chunked']],
            null,
        ];
    }

    /**
     * @dataProvider invalidResponseFramingProvider
     *
     * @param array<string, string[]> $headers
     */
    public function testRejectsInvalidResponseFraming(
        string $method,
        int $status,
        array $headers,
        string $expectedMessage
    ): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        HeaderProcessor::validateResponseFraming($method, $status, $headers);
    }

    public static function invalidResponseFramingProvider(): iterable
    {
        yield 'malformed' => [
            'GET',
            200,
            ['Content-Length' => ['three']],
            'Invalid response Content-Length header: value is not a non-negative decimal integer',
        ];
        yield 'conflicting mixed-case duplicates' => [
            'GET',
            200,
            ['Content-Length' => ['3'], 'content-length' => ['5']],
            'Invalid response Content-Length header: values conflict',
        ];
        yield 'content length and transfer encoding' => [
            'GET',
            200,
            ['Content-Length' => ['0'], 'tRaNsFeR-EnCoDiNg' => ['chunked']],
            'Response contains both Transfer-Encoding and Content-Length',
        ];
        yield 'reset content remains framing-validated' => [
            'GET',
            205,
            ['Content-Length' => ['0'], 'Transfer-Encoding' => ['chunked']],
            'Response contains both Transfer-Encoding and Content-Length',
        ];
    }

    public function testRejectsUnrepresentableResponseContentLength(): void
    {
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Content-Length exceeds the maximum integer size supported on this platform');

        HeaderProcessor::validateResponseFraming('GET', 200, ['Content-Length' => [((string) \PHP_INT_MAX).'0']]);
    }

    /**
     * @dataProvider contentLengthToIntProvider
     */
    public function testConvertsContentLengthToIntWhenRepresentable(?string $length, ?int $expected): void
    {
        self::assertSame($expected, HeaderProcessor::contentLengthToInt($length));
    }

    public static function contentLengthToIntProvider(): iterable
    {
        $max = (string) \PHP_INT_MAX;

        return [
            'absent' => [null, null],
            'zero' => ['0', 0],
            'simple' => ['3', 3],
            'php int max' => [$max, \PHP_INT_MAX],
            'equal length too large' => [\str_repeat('9', \strlen($max)), null],
            'longer than php int max' => [$max.'0', null],
        ];
    }

    public function testAcceptsRepresentableContentLengthPlatformLimits(): void
    {
        HeaderProcessor::assertContentLengthWithinPlatformLimit(null);
        HeaderProcessor::assertContentLengthWithinPlatformLimit('0');
        HeaderProcessor::assertContentLengthWithinPlatformLimit((string) \PHP_INT_MAX);

        $this->addToAssertionCount(1);
    }

    public function testRejectsUnrepresentableContentLengthPlatformLimit(): void
    {
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Content-Length exceeds the maximum integer size supported on this platform');

        HeaderProcessor::assertContentLengthWithinPlatformLimit(((string) \PHP_INT_MAX).'0');
    }

    /**
     * @dataProvider invalidContentLengthProvider
     *
     * @param string[] $values
     */
    public function testRejectsInvalidContentLength(array $values): void
    {
        $this->expectException(\RuntimeException::class);

        HeaderProcessor::parseContentLength($values);
    }

    public static function invalidContentLengthProvider(): iterable
    {
        return [
            'empty' => [['']],
            'ows only' => [[" \t"]],
            'empty first member' => [[', 3']],
            'empty last member' => [['3,']],
            'empty middle member' => [['3,,3']],
            'signed positive' => [['+3']],
            'signed negative' => [['-3']],
            'decimal' => [['3.0']],
            'partial numeric' => [['3abc']],
            'conflicting comma' => [['3, 5']],
            'conflicting duplicate' => [['3', '5']],
        ];
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

    public function testRejectsMissingProtocolVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP version missing from header data');

        HeaderProcessor::parseHeaders([
            'FTP/1.1 200 OK',
        ]);
    }

    /**
     * @dataProvider invalidProtocolVersionProvider
     */
    public function testRejectsMalformedProtocolVersion(string $statusLine): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP version is invalid');

        HeaderProcessor::parseHeaders([$statusLine]);
    }

    public static function invalidProtocolVersionProvider(): iterable
    {
        yield ['HTTP/foo 200 OK'];
        yield ['HTTP/ 200 OK'];
        yield ['HTTP/1.1.1 200 OK'];
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

    public function testRejectsMalformedReasonPhrase(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP reason phrase is invalid');

        HeaderProcessor::parseHeaders([
            "HTTP/1.1 200 OK\x00",
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

    public function testRejectsStatusCodeWithTrailingNewline(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status code is invalid');

        HeaderProcessor::parseHeaders([
            "HTTP/1.1 200\n",
        ]);
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
