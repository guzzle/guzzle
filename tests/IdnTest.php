<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\HostValidator;
use GuzzleHttp\Idn;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

class IdnTest extends TestCase
{
    /**
     * @requires extension idn
     */
    public function testConvertUri(): void
    {
        $uri = Psr7\Utils::uriFor('https://яндекс.рф/images');
        $uri = Idn::convertUri($uri);
        self::assertSame('xn--d1acpjx3f.xn--p1ai', $uri->getHost());
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
