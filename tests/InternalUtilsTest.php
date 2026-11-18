<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class InternalUtilsTest extends TestCase
{
    public function testCurrentTime()
    {
        self::assertGreaterThan(0, Utils::currentTime());
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testIdnConvert()
    {
        $uri = Psr7\Utils::uriFor('https://яндекс.рф/images');
        $uri = Utils::idnUriConvert($uri);
        self::assertSame('xn--d1acpjx3f.xn--p1ai', $uri->getHost());
    }

    /**
     * @requires extension intl
     */
    public function testIdnConvertRejectsAHostLongerThan4096Bytes()
    {
        // The conversion ignores soft hyphens, so only the length differs.
        $padding = str_repeat("\u{00AD}", 2042);
        $uri = new Psr7\Uri('http://placeholder.test/');

        self::assertSame('example.test', Utils::idnUriConvert($uri->withHost($padding.'example.test'), \IDNA_DEFAULT)->getHost());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IDN conversion failed');

        Utils::idnUriConvert($uri->withHost($padding.'examples.test'), \IDNA_DEFAULT);
    }

    /**
     * @requires extension intl
     */
    public function testIdnConvertRejectsAnOverlongLabelWhenIntlThrowsExceptions()
    {
        // Maps to 1200 characters, more than ICU encodes in one label.
        $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost(str_repeat("\u{3316}", 200).'.example');
        $useExceptions = \ini_set('intl.use_exceptions', '1');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('IDN conversion failed');

            Utils::idnUriConvert($uri, \IDNA_DEFAULT);
        } finally {
            \ini_set('intl.use_exceptions', $useExceptions);
        }
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testIdnConvertRejectsAHostThatConvertsToAnInvalidHost()
    {
        // The fullwidth solidus maps to a solidus.
        $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost("evil.example\u{FF0F}.good.example");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IDN conversion failed');

        Utils::idnUriConvert($uri, \IDNA_DEFAULT);
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testIdnConversionCanProduceANoncanonicalNumericHost()
    {
        // idn_conversion is not an SSRF control; IDNA can produce numeric IPv4
        // shorthand that 7.15 accepts.
        $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost("\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF11}");

        self::assertSame('127.1', Utils::idnUriConvert($uri, \IDNA_DEFAULT)->getHost());
    }

    /**
     * @requires function idn_to_ascii
     */
    public function testIdnConversionCanProduceAnIpv4AddressWithARootDot()
    {
        $uri = (new Psr7\Uri('http://placeholder.test/'))->withHost("127.0.0.\u{FF11}\u{3002}");

        self::assertSame('127.0.0.1.', Utils::idnUriConvert($uri, \IDNA_DEFAULT)->getHost());
    }
}
