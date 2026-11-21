<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\HostValidator;
use GuzzleHttp\Idn;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

class IdnTest extends TestCase
{
    public function testNormalizeConversionOptionTreatsNullAsOff(): void
    {
        self::assertFalse(Idn::normalizeConversionOption(null));
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUri(): void
    {
        $uri = Idn::convertUri(new UnvalidatedUri('https', 'яндекс.рф', null, '/images'), true);

        self::assertSame('https://xn--d1acpjx3f.xn--p1ai/images', (string) $uri);
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUriConvertsAPercentEncodedHost(): void
    {
        $uri = Idn::convertUri(new UnvalidatedUri('https', 'b%C3%BCcher.example'), true);

        self::assertSame('xn--bcher-kva.example', $uri->getHost());
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUriUsesTheGivenOptions(): void
    {
        $uri = new UnvalidatedUri('https', 'faß.de');

        self::assertSame('fass.de', Idn::convertUri($uri, 0)->getHost());
        self::assertSame('xn--fa-hia.de', Idn::convertUri($uri, true)->getHost());
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUriAppliesTheStd3RulesWhenTheOptionIsTrue(): void
    {
        $uri = new UnvalidatedUri('https', 'é_b.test');

        self::assertSame('xn--_b-9ia.test', Idn::convertUri($uri, \IDNA_DEFAULT)->getHost());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to convert host to ASCII');

        Idn::convertUri($uri, true);
    }

    public function testConvertUriLeavesAnAsciiHostAlone(): void
    {
        $uri = new UnvalidatedUri('https', 'Example_Host.test');

        self::assertSame($uri, Idn::convertUri($uri, true));
    }

    public function testConvertUriLeavesTheUriAloneWhenConversionIsOff(): void
    {
        $uri = new UnvalidatedUri('https', 'яндекс.рф');

        self::assertSame($uri, Idn::convertUri($uri, false));
    }

    /**
     * @requires extension intl
     */
    public function testConvertUriRejectsAnOverlongLabelWhenIntlThrowsExceptions(): void
    {
        // Maps to 1200 characters, more than ICU encodes in one label.
        $uri = new UnvalidatedUri('http', str_repeat("\u{3316}", 200).'.example');
        $useExceptions = \ini_set('intl.use_exceptions', '1');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unable to convert host to ASCII');

            Idn::convertUri($uri, true);
        } finally {
            \ini_set('intl.use_exceptions', $useExceptions);
        }
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUriRejectsAnInvalidHostThatAnotherUriImplementationAccepts(): void
    {
        // Fullwidth forms map to URI delimiters.
        $hosts = [
            "good.example\u{FF20}evil.example",
            "evil.example\u{FF0F}.good.example",
            "evil.example\u{FF1F}.good.example",
            "evil.example\u{FF03}.good.example",
            "good.example\u{FF1A}8080",
        ];

        foreach ($hosts as $host) {
            try {
                Idn::convertUri(new UnvalidatedUri('http', $host), \IDNA_DEFAULT);
                self::fail('An exception was not thrown');
            } catch (InvalidArgumentException $e) {
                self::assertStringStartsWith('Unable to convert host to ASCII', $e->getMessage());
            }
        }
    }

    /**
     * @requires function idn_to_ascii
     *
     * @dataProvider uriStringProvider
     */
    public function testCreateUri(string $uri, string $expected): void
    {
        self::assertSame($expected, (string) Idn::createUri(new Psr7\HttpFactory(), $uri, true));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function uriStringProvider(): iterable
    {
        yield 'raw host between percent-encoded data' => ['https://%C3%BC@яндекс.рф/%D0%BF', 'https://%C3%BC@xn--d1acpjx3f.xn--p1ai/%D0%BF'];
        yield 'percent-encoded host' => ['https://%D1%8F.%D1%80%D1%84/images', 'https://xn--41a.xn--p1ai/images'];
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testCreateUriRejectsAStringThatCannotBeParsed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to parse URI');

        Idn::createUri(new Psr7\HttpFactory(), 'http://example.com:99999/', true);
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConversionCanProduceANoncanonicalNumericHost(): void
    {
        $mappings = [
            "\u{FF10}\u{FF58}\u{FF17}\u{FF46}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF11}" => '0x7f000001',
            "\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF11}" => '127.1',
            "\u{FF12}\u{FF11}\u{FF13}\u{FF10}\u{FF17}\u{FF10}\u{FF16}\u{FF14}\u{FF13}\u{FF13}" => '2130706433',
            "\u{FF10}\u{FF11}\u{FF17}\u{FF17}\u{FF0E}\u{FF10}\u{FF0E}\u{FF10}\u{FF0E}\u{FF11}" => '0177.0.0.1',
            "\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF10}\u{3002}\u{FF10}\u{3002}\u{FF11}" => '127.0.0.1',
            "127.0.0.\u{FF11}\u{3002}" => '127.0.0.1.',
        ];

        foreach ($mappings as $unicode => $expected) {
            self::assertSame($expected, Idn::convertUri(new UnvalidatedUri('http', $unicode), true)->getHost());
        }

        // The trailing-dot form the conversion can produce is rejected.
        $rootDot = Idn::convertUri(new UnvalidatedUri('http', "127.0.0.\u{FF11}\u{3002}"), true);

        try {
            HostValidator::assertRequestHost(new Psr7\Request('GET', $rootDot));
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('must not be written as one to four decimal, octal or hexadecimal parts', $e->getMessage());
        }

        // idn_conversion is not an SSRF control; numeric shorthand stays valid.
        $shorthand = Idn::convertUri(new UnvalidatedUri('http', "\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF11}"), true);

        HostValidator::assertRequestHost(new Psr7\Request('GET', $shorthand));

        self::assertSame('127.1', $shorthand->getHost());
    }
}
