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
