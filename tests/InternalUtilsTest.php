<?php

namespace GuzzleHttp\Tests;

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
     * @requires extension idn
     */
    public function testIdnConvert()
    {
        $uri = Psr7\Utils::uriFor('https://яндекс.рф/images');
        $uri = Utils::idnUriConvert($uri);
        self::assertSame('xn--d1acpjx3f.xn--p1ai', $uri->getHost());
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
