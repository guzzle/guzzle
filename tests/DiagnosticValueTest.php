<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\DiagnosticValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\DiagnosticValue
 */
class DiagnosticValueTest extends TestCase
{
    /**
     * @dataProvider diagnosticValueProvider
     */
    public function testEscapesControls(string $value, string $expected): void
    {
        self::assertSame($expected, DiagnosticValue::escapeControls($value));
    }

    public static function diagnosticValueProvider(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'printable ASCII' => ['plain text', 'plain text'];
        yield 'printable UTF-8' => ['déjà vu', 'déjà vu'];
        yield 'C0 and DEL' => ["\x00\x09\x1B\x7F", '\\x00\\x09\\x1B\\x7F'];
        yield 'UTF-8 C1' => ["\u{0080}\u{009B}", '\\x80\\x9B'];
        yield 'malformed UTF-8' => ["A\xC3(B\xFF", 'A\\xC3(B\\xFF'];
    }
}
