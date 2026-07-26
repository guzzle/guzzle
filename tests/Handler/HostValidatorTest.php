<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\HostValidator;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * @covers \GuzzleHttp\Handler\HostValidator
 */
class HostValidatorTest extends TestCase
{
    public static function nonPrintableAsciiHostProvider(): iterable
    {
        yield 'soft hyphen' => ["e\u{00AD}vil.test"];
        yield 'zero width space' => ["e\u{200B}vil.test"];
        yield 'word joiner' => ["e\u{2060}vil.test"];
        yield 'byte order mark' => ["e\u{FEFF}vil.test"];
        yield 'mongolian vowel separator' => ["e\u{180E}vil.test"];
        yield 'combining grapheme joiner' => ["e\u{034F}vil.test"];
        yield 'hangul choseong filler' => ["e\u{115F}vil.test"];
        yield 'hangul filler' => ["e\u{3164}vil.test"];
        yield 'fullwidth letter' => ["\u{FF45}vil.test"];
        yield 'ideographic full stop' => ["evil\u{3002}test"];
        yield 'fullwidth numeric loopback' => ["\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF10}\u{3002}\u{FF10}\u{3002}\u{FF11}"];
        yield 'zero width space in numeric host' => ["127.0.0.\u{200B}1"];
        yield 'u-label' => ['münchen.example'];
        yield 'kelvin sign' => ["\u{212A}elvin.test"];
        yield 'sharp s' => ['straße.test'];
        // The non-ASCII branch wins over the percent branch.
        yield 'mixed raw and encoded' => ["e\xE2%80%8Bvil.test"];
        // The validator and escape() are bytewise, so this row catches a switch
        // to Psr7\DiagnosticValue::escape(), whose /u match fails here.
        yield 'raw over-long utf-8' => ["e\xC0\xAEvil.test"];
    }

    public static function nonPrintableAsciiHostHeaderProvider(): iterable
    {
        yield from self::nonPrintableAsciiHostProvider();

        // Uri::withHost() rejects a space, so this row is header-only.
        yield 'space' => ['evil test'];
    }

    public static function percentEncodedUriHostProvider(): iterable
    {
        yield 'encoded soft hyphen' => ['e%C2%ADvil.test'];
        yield 'encoded zero width space' => ['e%E2%80%8Bvil.test'];
        yield 'encoded word joiner' => ['e%E2%81%A0vil.test'];
        yield 'encoded byte order mark' => ['e%EF%BB%BFvil.test'];
        yield 'encoded ascii letter' => ['%65vil.test'];
        yield 'encoded dots' => ['127%2e0%2e0%2e1'];
        yield 'encoded final octet' => ['127.0.0.%31'];
        yield 'fully encoded loopback' => ['%31%32%37.0.0.1'];
        yield 'encoded zero width space in numeric host' => ['127.0.0.%E2%80%8B1'];
        yield 'over-long utf-8' => ['e%C0%AEvil.test'];
        yield 'uppercase hex' => ['127%2E0%2E0%2E1'];
    }

    public static function foreignUriHostProvider(): iterable
    {
        yield 'trailing percent' => ['evil.test%', 'must not contain a percent escape'];
        yield 'invalid hex' => ['ex%zzvil.test', 'must not contain a percent escape'];
        yield 'zone identifier' => ['[::1%25en0]', 'must not contain a percent escape'];
        yield 'double encoded' => ['%2565vil.test', 'must not contain a percent escape'];
        yield 'userinfo' => ['blocked.example.com@127.0.0.1', 'must be a valid RFC 3986 host'];
        yield 'path' => ['evil.test/x', 'must be a valid RFC 3986 host'];
        yield 'query' => ['evil.test?x', 'must be a valid RFC 3986 host'];
        yield 'fragment' => ['evil.test#x', 'must be a valid RFC 3986 host'];
        yield 'backslash' => ['evil.test\\x', 'must be a valid RFC 3986 host'];
        yield 'bare colon' => ['evil.test:8080', 'must be a valid RFC 3986 host'];
        yield 'unclosed bracket' => ['[::1', 'must be a valid RFC 3986 host'];
        yield 'bracketed name' => ['[evil.test]', 'must be a valid RFC 3986 host'];
        yield 'bracketed non-address' => ['[not:an:ip]', 'must be a valid RFC 3986 host'];
        yield 'uppercase hexadecimal root dot' => ['0X7F000001.', 'must not be written as one to four decimal, octal or hexadecimal parts'];
    }

    public static function acceptedHostProvider(): iterable
    {
        yield 'dns name' => ['example.com'];
        yield 'subdomain' => ['a.b.example.com'];
        yield 'single label' => ['localhost'];
        yield 'underscore' => ['my_svc'];
        yield 'underscore label' => ['_dmarc.example.com'];
        yield 'leading hyphen label' => ['-lead.example.test'];
        yield 'trailing root dot' => ['example.test.'];
        yield 'a-label' => ['xn--d1acpjx3f.xn--p1ai'];
        yield 'invalid punycode a-label' => ['xn--0.test'];
        yield 'sub-delims' => ['foo!bar.test'];
        yield 'tilde' => ['foo~bar.test'];
        yield 'ipv4' => ['127.0.0.1'];
        yield 'ipv6' => ['[::1]'];
        yield 'noncanonical ipv6' => ['[0:0:0:0:0:0:0:1]'];
        yield 'uppercase' => ['EXAMPLE.COM'];
        yield 'shortened numeric' => ['127.1'];
        yield 'integer numeric' => ['2130706433'];
        yield 'octal numeric' => ['0177.0.0.1'];
        yield 'hexadecimal numeric' => ['0x7f000001'];
        yield 'zero padded numeric' => ['127.000.000.001'];
        yield 'out of range numeric' => ['127.0.0.256'];
        // The negative controls for the trailing-dot rule.
        yield 'root dot on a name whose last label is numeric' => ['host.123.'];
        yield 'root dot on five numeric groups' => ['1.2.3.4.5.'];
        yield 'root dot on a malformed octal' => ['09.'];
        yield 'root dot on an empty part' => ['127..1.'];
    }

    public static function numericRightmostLabelHostProvider(): iterable
    {
        yield 'numeric label' => ['host.123'];
        yield 'five groups' => ['1.2.3.4.5'];
        yield 'hex label' => ['foo.0x1'];
    }

    public static function trailingRootDotIpv4HostProvider(): iterable
    {
        yield 'loopback' => ['127.0.0.1.'];
        yield 'private' => ['10.0.0.1.'];
        yield 'any' => ['0.0.0.0.'];
        yield 'broadcast' => ['255.255.255.255.'];
        yield 'shortened' => ['127.1.'];
        yield 'single part' => ['0.'];
        yield 'integer' => ['2130706433.'];
        yield 'hexadecimal' => ['0x7f000001.'];
        yield 'octal' => ['0177.0.0.1.'];
        yield 'mixed base' => ['0x7f.1.'];
        yield 'zero padded' => ['127.000.000.001.'];
        yield 'zero padded octet' => ['127.0.0.01.'];
        // Pin fail-closed cases that libcurl rejects or reads as names.
        yield 'two root dots' => ['127.0.0.1..'];
        yield 'out of range' => ['127.0.0.256.'];
        yield 'overflowing part' => ['0x100000000.'];
    }

    /**
     * @dataProvider nonPrintableAsciiHostProvider
     */
    public function testRejectsANonPrintableAsciiUriHost(string $host): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost($host));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must contain only printable ASCII characters');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider nonPrintableAsciiHostHeaderProvider
     */
    public function testRejectsANonPrintableAsciiHostHeader(string $host): void
    {
        $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', $host);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The request Host header');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider percentEncodedUriHostProvider
     */
    public function testRejectsAPercentEncodedUriHost(string $host): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost($host));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not contain a percent escape');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider foreignUriHostProvider
     */
    public function testRejectsAForeignUriHost(string $host, string $expected): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage($expected);

        HostValidator::assertRequestHost($this->foreignHostRequest($host));
    }

    /**
     * @dataProvider trailingRootDotIpv4HostProvider
     */
    public function testRejectsANumericIpv4UriHostWithATrailingRootDot(string $host): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost($host));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not be written as one to four decimal, octal or hexadecimal parts');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider acceptedHostProvider
     */
    public function testAcceptsATransportSafeUriHost(string $host): void
    {
        $uri = (new Uri('http://placeholder.test/'))->withHost($host);
        $request = new Request('GET', $uri);

        HostValidator::assertRequestHost($request);

        self::assertSame($uri->getHost(), $request->getUri()->getHost());
    }

    /**
     * @dataProvider numericRightmostLabelHostProvider
     */
    public function testAcceptsAHostWhoseRightmostLabelIsNumeric(string $host): void
    {
        $uri = (new Uri('http://placeholder.test/'))->withHost($host);
        $request = new Request('GET', $uri);

        HostValidator::assertRequestHost($request);

        self::assertSame($uri->getHost(), $request->getUri()->getHost());
    }

    public function testAcceptsAPercentEncodedHostHeader(): void
    {
        foreach (['%65vil.test', '[fe80::1%25eth0]'] as $value) {
            $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', $value);

            HostValidator::assertRequestHost($request);

            self::assertSame($value, $request->getHeaderLine('Host'));
        }
    }

    public function testAcceptsAHostHeaderWithAPort(): void
    {
        foreach (['example.com:8080', '[::1]:8080'] as $value) {
            $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', $value);

            HostValidator::assertRequestHost($request);

            self::assertSame($value, $request->getHeaderLine('Host'));
        }
    }

    public function testAcceptsAnExplicitAsciiHostHeaderThatDiffersFromTheUri(): void
    {
        $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', 'other.example');

        HostValidator::assertRequestHost($request);

        self::assertSame('other.example', $request->getHeaderLine('Host'));
    }

    public function testAcceptsMultipleAsciiHostHeaderValues(): void
    {
        $request = (new Request('GET', 'http://example.com/'))
            ->withHeader('Host', 'a.example')
            ->withAddedHeader('Host', 'b.example');

        HostValidator::assertRequestHost($request);

        self::assertSame(['a.example', 'b.example'], $request->getHeader('Host'));
    }

    public function testAcceptsAnEmptyUriHost(): void
    {
        $request = new Request('GET', '/relative');

        HostValidator::assertRequestHost($request);

        self::assertSame('', $request->getUri()->getHost());
    }

    public function testEscapesNonPrintableBytesInTheMessage(): void
    {
        try {
            HostValidator::assertRequestHost($this->foreignHostRequest("evil\x01.test"));
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('evil\\x01.test', $e->getMessage());
            self::assertStringNotContainsString("\x01", $e->getMessage());
        }
    }

    public function testExceptionCarriesTheRequest(): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost("e\u{200B}vil.test"));

        try {
            HostValidator::assertRequestHost($request);
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(GuzzleException::class, $e);
        }
    }

    private function foreignHostRequest(string $host): RequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn($host);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeader')->with('Host')->willReturn([]);

        return $request;
    }
}
