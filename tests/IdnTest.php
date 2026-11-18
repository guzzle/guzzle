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
    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUri(): void
    {
        $uri = Psr7\Utils::uriFor('https://яндекс.рф/images');
        $uri = Idn::convertUri($uri);
        self::assertSame('xn--d1acpjx3f.xn--p1ai', $uri->getHost());
    }

    /**
     * @requires extension intl
     */
    public function testConvertUriRejectsAHostLongerThan4096Bytes(): void
    {
        // The conversion ignores soft hyphens, so only the length differs.
        $padding = str_repeat("\u{00AD}", 2042);
        $uri = new Psr7\Uri('http://placeholder.test/');

        self::assertSame('example.test', Idn::convertUri($uri->withHost($padding.'example.test'), \IDNA_DEFAULT)->getHost());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IDN conversion failed');

        Idn::convertUri($uri->withHost($padding.'examples.test'), \IDNA_DEFAULT);
    }

    /**
     * @requires extension intl
     */
    public function testConvertUriRejectsAnOverlongLabelWhenIntlThrowsExceptions(): void
    {
        // Maps to 1200 characters, more than ICU encodes in one label.
        $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost(str_repeat("\u{3316}", 200).'.example');
        $useExceptions = \ini_set('intl.use_exceptions', '1');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('IDN conversion failed');

            Idn::convertUri($uri, \IDNA_DEFAULT);
        } finally {
            \ini_set('intl.use_exceptions', $useExceptions);
        }
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testConvertUriRejectsAHostThatConvertsToAnInvalidHost(): void
    {
        // The fullwidth solidus maps to a solidus.
        $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost("evil.example\u{FF0F}.good.example");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IDN conversion failed');

        Idn::convertUri($uri, \IDNA_DEFAULT);
    }

    /**
     * @requires function idn_to_ascii
     *
     * @dataProvider uriStringProvider
     */
    public function testConvertUriString(string $uri, string $expected): void
    {
        self::assertSame($expected, Idn::convertUriString($uri, \IDNA_DEFAULT));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function uriStringProvider(): iterable
    {
        yield 'raw host' => ['https://яндекс.рф/images', 'https://xn--d1acpjx3f.xn--p1ai/images'];
        yield 'network-path reference' => ['//яндекс.рф/images', '//xn--d1acpjx3f.xn--p1ai/images'];
        yield 'host and port without a scheme' => ['яндекс.рф:8080/images', 'xn--d1acpjx3f.xn--p1ai:8080/images'];
        yield 'userinfo before the host' => ['https://user@яндекс.рф:8443/', 'https://user@xn--d1acpjx3f.xn--p1ai:8443/'];
        yield 'the rest of the string is kept as given' => ['HTTPS://Яндекс.рф:443/a b?#', 'HTTPS://xn--d1acpjx3f.xn--p1ai:443/a b?#'];
        yield 'percent-encoded host' => ['https://%D1%8F%D0%BD%D0%B4%D0%B5%D0%BA%D1%81.%D1%80%D1%84/images', 'https://%D1%8F%D0%BD%D0%B4%D0%B5%D0%BA%D1%81.%D1%80%D1%84/images'];
        yield 'percent-encoded data before raw data' => ['https://b%c3%bcché.example/', 'https://b%c3%bcché.example/'];
        yield 'percent-encoded data after raw data' => ['https://buché%C3%A9.example/', 'https://buché%C3%A9.example/'];
        yield 'ASCII URI' => ['HTTPS://Example.com:443/a b', 'HTTPS://Example.com:443/a b'];
        yield 'non-ASCII path' => ['https://example.com/путь', 'https://example.com/путь'];
        yield 'non-ASCII userinfo' => ['https://üser:pässword@example.com/', 'https://üser:pässword@example.com/'];
        yield 'relative reference' => ['путь', 'путь'];
        yield 'long string without a delimiter' => [str_repeat('a', 500000), str_repeat('a', 500000)];
        yield 'colon-leading path with a query' => ['яндекс.рф:8080?x', 'яндекс.рф:8080?x'];
        yield 'colon-leading path after a scheme' => ['foo:é:80/', 'foo:é:80/'];
        yield 'backslash before the host' => ['http://example.com\\@яндекс.рф/', 'http://example.com\\@яндекс.рф/'];
        yield 'backslash after the port' => ['http://яндекс.рф:80\\/images', 'http://яндекс.рф:80\\/images'];
        yield 'non-ASCII data after a later double slash' => ['https://example.com/?next=//яндекс.рф/', 'https://example.com/?next=//яндекс.рф/'];
        yield 'unconvertible host' => ['https://-яндекс.рф/images', 'https://-яндекс.рф/images'];
        yield 'unparseable URI' => ['https://яндекс.рф:port/', 'https://яндекс.рф:port/'];
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
            $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost($unicode);

            self::assertSame($expected, Idn::convertUri($uri, \IDNA_DEFAULT)->getHost());
        }

        // The trailing-dot form the conversion can produce is rejected.
        $rootDot = Idn::convertUri((new Psr7\Uri('http://placeholder.test/'))->withHost("127.0.0.\u{FF11}\u{3002}"), \IDNA_DEFAULT);

        try {
            HostValidator::assertRequestHost(new Psr7\Request('GET', $rootDot));
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('must not be written as one to four decimal, octal or hexadecimal parts', $e->getMessage());
        }

        // idn_conversion is not an SSRF control; numeric shorthand stays valid.
        $shorthand = Idn::convertUri((new Psr7\Uri('http://placeholder.test/'))->withHost("\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF11}"), \IDNA_DEFAULT);

        HostValidator::assertRequestHost(new Psr7\Request('GET', $shorthand));

        self::assertSame('127.1', $shorthand->getHost());
    }
}
