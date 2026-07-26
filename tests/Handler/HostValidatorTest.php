<?php

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
    public static function nonAsciiHostProvider(): iterable
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
        yield 'mixed raw and encoded' => ["e\xE2%80%8Bvil.test"];
        yield 'raw over-long utf-8' => ["e\xC0\xAEvil.test"];
    }

    public static function nonPrintableHostProvider(): iterable
    {
        yield 'space' => ['evil test'];
        yield 'tab' => ["evil\ttest"];
        yield 'delete' => ["evil\x7Ftest"];
        yield 'control byte' => ["evil\x01.test"];
    }

    public static function nonPrintableHostHeaderProvider(): iterable
    {
        yield 'space' => ['evil test'];
        yield 'tab' => ["evil\ttest"];
    }

    public static function percentEncodedHostProvider(): iterable
    {
        yield 'encoded soft hyphen' => ['e%C2%ADvil.test'];
        yield 'encoded zero width space' => ['e%E2%80%8Bvil.test'];
        yield 'encoded word joiner' => ['e%E2%81%A0vil.test'];
        yield 'encoded byte order mark' => ['e%EF%BB%BFvil.test'];
        yield 'encoded ascii letter' => ['%65vil.test'];
        yield 'encoded dots' => ['127%2e0%2e0%2e1'];
        yield 'uppercase hex' => ['127%2E0%2E0%2E1'];
        yield 'encoded final octet' => ['127.0.0.%31'];
        yield 'fully encoded loopback' => ['%31%32%37.0.0.1'];
        yield 'encoded zero width space in numeric host' => ['127.0.0.%E2%80%8B1'];
        yield 'double encoded' => ['%2565vil.test'];
        yield 'over-long utf-8' => ['e%C0%AEvil.test'];
        yield 'trailing percent' => ['evil.test%'];
        yield 'invalid hex' => ['ex%zzvil.test'];
        yield 'zone identifier' => ['[::1%25en0]'];
    }

    public static function authorityDelimiterHostProvider(): iterable
    {
        yield 'userinfo' => ['blocked.example.com@127.0.0.1'];
        yield 'path' => ['evil.test/x'];
        yield 'query' => ['evil.test?x'];
        yield 'fragment' => ['evil.test#x'];
        yield 'backslash' => ['evil.test\\x'];
        yield 'bare colon' => ['evil.test:8080'];
        yield 'unclosed bracket' => ['[::1'];
        yield 'trailing bracket' => ['[::1]x'];
        yield 'interior brackets' => ['a[b]c'];
    }

    public static function rootDotAddressHostProvider(): iterable
    {
        yield 'loopback' => ['127.0.0.1.'];
        yield 'private range' => ['10.0.0.1.'];
        yield 'any address' => ['0.0.0.0.'];
        yield 'broadcast' => ['255.255.255.255.'];
        yield 'shortened decimal' => ['127.1.'];
        yield 'single part decimal' => ['0.'];
        yield 'whole integer' => ['2130706433.'];
        yield 'hexadecimal' => ['0x7f000001.'];
        yield 'octal' => ['0177.0.0.1.'];
        yield 'mixed base' => ['0x7f.1.'];
        yield 'zero padded octets' => ['127.000.000.001.'];
        yield 'zero padded final octet' => ['127.0.0.01.'];
        // Keep multiple dots fail-closed if libcurl relaxes its current guard.
        yield 'two root dots' => ['127.0.0.1..'];
        // Fail closed when libcurl reads out-of-range numeric shapes as names.
        yield 'octet out of range' => ['256.0.0.1.'];
        yield 'overflowing part' => ['0x100000000.'];
    }

    public static function acceptedHostProvider(): iterable
    {
        yield 'dns name' => ['example.com'];
        yield 'subdomain' => ['www.example.com'];
        yield 'single label' => ['localhost'];
        yield 'underscore' => ['my_svc'];
        yield 'underscore label' => ['_dmarc.example.com'];
        yield 'leading hyphen label' => ['-lead.example.test'];
        yield 'trailing root dot' => ['example.test.'];
        yield 'a-label' => ['xn--d1acpjx3f.xn--p1ai'];
        yield 'invalid punycode a-label' => ['xn--0.test'];
        yield 'sub-delims' => ['foo!bar.test'];
        yield 'tilde' => ['foo~bar.test'];
        yield 'numeric rightmost label' => ['host.123'];
        yield 'numeric rightmost label with root dot' => ['host.123.'];
        yield 'five groups' => ['1.2.3.4.5'];
        yield 'five groups with root dot' => ['1.2.3.4.5.'];
        yield 'invalid octal octet with root dot' => ['127.0.0.08.'];
        yield 'empty part with root dot' => ['127..1.'];
        yield 'ipv4' => ['127.0.0.1'];
        yield 'ipv6' => ['[::1]'];
        yield 'noncanonical ipv6' => ['[0:0:0:0:0:0:0:1]'];
        yield 'uppercase' => ['EXAMPLE.COM'];
        // Long-standing inet_aton shorthand remains accepted on 7.15.
        yield 'shortened numeric' => ['127.1'];
        yield 'integer numeric' => ['2130706433'];
        yield 'octal numeric' => ['0177.0.0.1'];
        yield 'hexadecimal numeric' => ['0x7f000001'];
        yield 'zero padded numeric' => ['127.000.000.001'];
        yield 'single zero padded octet' => ['127.0.0.01'];
        yield 'out of range numeric' => ['127.0.0.256'];
    }

    /**
     * @dataProvider nonAsciiHostProvider
     */
    public function testRejectsANonAsciiUriHost(string $host): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost($host));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must contain only printable ASCII characters');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider nonPrintableHostProvider
     */
    public function testRejectsANonPrintableUriHostFromAForeignUriImplementation(string $host): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must contain only printable ASCII characters');

        HostValidator::assertRequestHost($this->requestWithUriHost($host));
    }

    /**
     * @dataProvider nonPrintableHostProvider
     */
    public function testGuzzleUriRejectsEveryNonPrintableHostItself(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Uri('http://placeholder.test/'))->withHost($host);
    }

    /**
     * @dataProvider nonAsciiHostProvider
     */
    public function testRejectsANonAsciiHostHeader(string $host): void
    {
        $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', $host);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The request Host header');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider nonPrintableHostHeaderProvider
     */
    public function testRejectsANonPrintableHostHeader(string $host): void
    {
        $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', $host);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The request Host header');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider percentEncodedHostProvider
     */
    public function testRejectsAPercentEncodedUriHost(string $host): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost($host));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not contain a percent escape');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider percentEncodedHostProvider
     */
    public function testRejectsAPercentEncodedHostHeader(string $host): void
    {
        $request = (new Request('GET', 'http://example.com/'))->withHeader('Host', $host);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The request Host header');

        HostValidator::assertRequestHost($request);
    }

    /**
     * @dataProvider authorityDelimiterHostProvider
     */
    public function testRejectsAUriHostWithAnAuthorityDelimiter(string $host): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not contain a URI authority delimiter');

        HostValidator::assertRequestHost($this->requestWithUriHost($host));
    }

    /**
     * @dataProvider authorityDelimiterHostProvider
     */
    public function testGuzzleUriRejectsEveryAuthorityDelimiterItself(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Uri('http://placeholder.test/'))->withHost($host);
    }

    /**
     * @dataProvider rootDotAddressHostProvider
     */
    public function testRejectsNumericLookingPartsWithARootDot(string $host): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost($host));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not be written as one to four decimal, octal or hexadecimal parts');

        HostValidator::assertRequestHost($request);
    }

    public function testRejectsAnUppercaseHexadecimalHostWithARootDot(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not be written as one to four decimal, octal or hexadecimal parts');

        HostValidator::assertRequestHost($this->requestWithUriHost('0X7F000001.'));
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
            HostValidator::assertRequestHost($this->requestWithUriHost("evil\x01 test"));
            self::fail('Must throw a RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('evil\\x01\\x20test', $e->getMessage());
            self::assertStringNotContainsString("\x01", $e->getMessage());
        }
    }

    public function testExceptionCarriesTheRequest(): void
    {
        $request = new Request('GET', (new Uri('http://placeholder.test/'))->withHost("e\u{200B}vil.test"));

        try {
            HostValidator::assertRequestHost($request);
            self::fail('Must throw a RequestException.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(GuzzleException::class, $e);
        }
    }

    private function requestWithUriHost(string $host): RequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn($host);
        $uri->method('__toString')->willReturn('http://'.$host.':1/');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeader')->willReturn([]);

        return $request;
    }
}
