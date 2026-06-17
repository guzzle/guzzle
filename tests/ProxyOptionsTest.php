<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

class ProxyOptionsTest extends TestCase
{
    public static function proxySelectionProvider(): array
    {
        return [
            ['http://example.com', null, null, false, false],
            ['http://example.com', 'http://proxy.example.com:8080', 'http://proxy.example.com:8080', false, false],
            ['http://example.com', '', null, false, true],
            ['http://example.com', ['https' => 'http://proxy.example.com:8080'], null, false, false],
            ['http://example.com', ['http' => 'http://proxy.example.com:8080'], 'http://proxy.example.com:8080', false, false],
            ['http://example.com', ['http' => 'http://proxy.example.com:8080', 'no' => null], 'http://proxy.example.com:8080', false, false],
            ['http://example.com', ['http' => null], null, false, false],
            ['http://example.com', ['http' => ''], null, false, true],
            ['http://example.com', ['http' => 'http://proxy.example.com:8080', 'no' => ['example.com']], null, true, false],
            ['http://example.com', ['http' => 'http://proxy.example.com:8080', 'no' => 'example.com,localhost'], null, true, false],
            ['http://example.com', ['http' => 'http://proxy.example.com:8080', 'no' => ''], 'http://proxy.example.com:8080', false, false],
            ['http://foo.example.com', ['http' => 'http://proxy.example.com:8080', 'no' => ['.example.com']], null, true, false],
            ['https://example.com', ['http' => 'http://proxy.example.com:8080', 'no' => ['*']], null, true, false],
            ['http://internal.test', ['no' => ['internal.test']], null, true, false],
            ['http://example.com', ['no' => ['internal.test']], null, false, false],
            ['http://internal.test', ['no' => 'internal.test other.test'], null, true, false],
            ['http://other.test', ['no' => 'internal.test other.test'], null, true, false],
            ['http://anything.test', ['no' => ['*']], null, true, false],
            ['/relative-path', ['no' => ['*']], null, false, false],
        ];
    }

    /**
     * @dataProvider proxySelectionProvider
     *
     * @param mixed $proxy
     */
    public function testResolvesProxySelection(string $uri, $proxy, ?string $expectedProxy, bool $expectedBypassed, bool $expectedDisabled): void
    {
        $selection = ProxyOptions::resolve(Psr7\Utils::uriFor($uri), $proxy);

        self::assertSame($expectedProxy, $selection->getProxy());
        self::assertSame($expectedProxy !== null, $selection->hasProxy());
        self::assertSame($expectedBypassed, $selection->isBypassed());
        self::assertSame($expectedDisabled, $selection->isDisabled());
        self::assertSame($expectedBypassed || $expectedDisabled, $selection->shouldDisableProxy());
    }

    public function testValidatesProxyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy must be a string or array');

        ProxyOptions::resolve(Psr7\Utils::uriFor('http://example.com'), new \stdClass());
    }

    public function testValidatesSchemeProxyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy values must be strings');

        ProxyOptions::resolve(Psr7\Utils::uriFor('http://example.com'), ['http' => new \stdClass()]);
    }

    public static function noProxyProvider(): array
    {
        return [
            ['mit.edu', ['.mit.edu'], true],
            ['foo.mit.edu', ['.mit.edu'], true],
            ['foo.mit.edu:123', ['.mit.edu'], true],
            ['mit.edu', ['mit.edu'], true],
            ['mit.edu', ['baz', 'mit.edu'], true],
            ['mit.edu', ['', '', 'mit.edu'], true],
            ['mit.edu', ['baz', '*'], true],
            ['0', ['0'], true],
            ['foo.example.com', ['example.com'], true],
            ['EXAMPLE.com', ['example.com'], true],
            ['example.com', ['EXAMPLE.com'], true],
            ['foo.example.com', ['EXAMPLE.com'], true],
            ['example.com.', ['example.com'], true],
            ['foo.example.com.', ['example.com'], true],
            ['example.com', ['example.com.'], true],
            ['foo.example.com', ['example.com.'], true],
            ['example.com.', ['.example.com'], true],
            ['foo.example.com.', ['.example.com'], true],
            ['foo.example.com', ['.example.com.'], true],
            ['example.com', ['.example.com.'], true],
            ['example.com', ['..example.com'], false],
            ['foo.example.com', ['..example.com'], false],
            ['example.com..', ['example.com'], false],
            ['.', ['*'], false],
            ['foo.example.com:123', ['.EXAMPLE.com'], true],
            ['example.com', ['.EXAMPLE.com'], true],
            ['example.com', ['example.com:443'], false],
            ['[::1]', ['[::1]'], true],
            ['[::1]', ['::1'], true],
            ['::1', ['::1'], true],
            ['[::1]', ['[::1]:8080'], false],
            ['[::1]:8080', ['::1'], true],
            ['[::1]:8080', ['[::1]'], true],
            ['[::1]:8080', ['[::1]:8080'], false],
            ['[fd00::1]', ['fd00::1'], true],
            ['[fd00::1]', ['[fd00::2]'], false],
            ['[2a00:f48:1008::212:183:10]', ['2a00:f48:1008::212:183:10'], true],
            ['[2a00:f48:1008::212:183:10]', ['[2a00:f48:1008::212:183:10]'], true],
            ['[2A00:F48:1008::212:183:10]', ['2a00:f48:1008::212:183:10'], true],
            ['[0:0:0:0:0:0:0:1]', ['::1'], true],
            ['[::1]', ['[0:0:0:0:0:0:0:1]'], true],
            ['example.com:443', ['example.com'], true],
            ['example.com:443', ['example.com:443'], false],
            ['127.0.0.1.', ['127.0.0.1'], false],
            ['127.0.0.1.', ['127.0.0.0/8'], false],
            ['[::1].', ['[::1]'], false],
            ['::1.', ['::1'], false],
            ['127.0.0.1', ['.127.0.0.1'], true],
            ['foo.127.0.0.1', ['127.0.0.1'], false],
            ['192.168.1.10', ['192.168.0.0/16'], true],
            ['192.168.1.10', ['.192.168.0.0/16'], true],
            ['192.169.1.10', ['192.168.0.0/16'], false],
            ['[fd00::1]', ['fd00::/8'], true],
            ['[fe80::1]', ['fd00::/8'], false],
        ];
    }

    /**
     * @dataProvider noProxyProvider
     */
    public function testChecksNoProxyList(string $host, array $list, bool $result): void
    {
        self::assertSame($result, ProxyOptions::isHostInNoProxy($host, $list));
    }

    public function testNormalizesNoProxyString(): void
    {
        self::assertSame(['foo.com', '.bar.com'], ProxyOptions::normalizeNoProxy(' foo.com, .bar.com, '));
        self::assertSame(['foo.com', 'bar.com'], ProxyOptions::normalizeNoProxy(' foo.com , bar.com , '));
        self::assertSame(['foo.com', 'bar.com'], ProxyOptions::normalizeNoProxy('foo.com,,bar.com,'));
        self::assertSame(['foo.com', 'bar.com'], ProxyOptions::normalizeNoProxy(" \tfoo.com\t , \nbar.com\n "));
        self::assertSame(['exa', 'mple.com', 'foo.com'], ProxyOptions::normalizeNoProxy('exa mple.com, foo.com'));
        self::assertSame(['a', 'b', 'c', 'd'], ProxyOptions::normalizeNoProxy("a b,c\td"));
        self::assertSame(['..example.com', '.example.org'], ProxyOptions::normalizeNoProxy('..example.com, .example.org'));
    }

    public function testNormalizesNoProxyArray(): void
    {
        self::assertSame(['foo.com', '.bar.com'], ProxyOptions::normalizeNoProxy([' foo.com ', '', '.bar.com']));
        self::assertSame(['foo.com', 'bar.com'], ProxyOptions::normalizeNoProxy([' foo.com ', '', ' bar.com ']));
        self::assertSame(['host1 host2'], ProxyOptions::normalizeNoProxy(['host1 host2']));
    }

    public function testNormalizesNullNoProxyValue(): void
    {
        self::assertSame([], ProxyOptions::normalizeNoProxy(null));
    }

    public function testValidatesNoProxyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be null, a string, or an array of strings');

        ProxyOptions::normalizeNoProxy(new \stdClass());
    }

    public function testValidatesNoProxyArrayValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be null, a string, or an array of strings');

        ProxyOptions::normalizeNoProxy(['foo.com', new \stdClass()]);
    }

    public function testValidatesNoProxyValueWhenResolvingProxy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be null, a string, or an array of strings');

        ProxyOptions::resolve(Psr7\Utils::uriFor('http://example.com'), [
            'http' => 'http://proxy.example.com:8080',
            'no' => new \stdClass(),
        ]);
    }

    public function testValidatesNoProxyArrayValueWhenResolvingProxy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be null, a string, or an array of strings');

        ProxyOptions::resolve(Psr7\Utils::uriFor('http://example.com'), [
            'http' => 'http://proxy.example.com:8080',
            'no' => [new \stdClass()],
        ]);
    }

    public function testValidatesNoProxyArrayValueWhenResolvingProxyWithoutSchemeKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be null, a string, or an array of strings');

        ProxyOptions::resolve(Psr7\Utils::uriFor('http://example.com'), [
            'no' => [123],
        ]);
    }

    public function testValidatesSchemeProxyValueBeforeNoProxyBypass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy values must be strings');

        ProxyOptions::resolve(Psr7\Utils::uriFor('https://example.com'), [
            'https' => 123,
            'no' => ['example.com'],
        ]);
    }

    public static function uriNoProxyProvider(): array
    {
        return [
            ['http://example.com', ['example.com:80'], true],
            ['https://example.com', ['example.com:443'], true],
            ['http://example.com', ['EXAMPLE.com'], true],
            ['http://foo.example.com', ['EXAMPLE.com'], true],
            ['http://foo.example.com', ['.EXAMPLE.com'], true],
            ['http://example.com', ['.EXAMPLE.com'], true],
            ['http://example.com.', ['example.com'], true],
            ['http://foo.example.com.', ['example.com'], true],
            ['http://example.com', ['example.com.'], true],
            ['http://foo.example.com', ['example.com.'], true],
            ['http://example.com.', ['.example.com'], true],
            ['http://foo.example.com.', ['.example.com'], true],
            ['http://foo.example.com', ['.example.com.'], true],
            ['http://example.com', ['.example.com.'], true],
            ['http://example.com', ['..example.com'], false],
            ['http://foo.example.com', ['..example.com'], false],
            ['http://example.com.:8080', ['example.com:8080'], true],
            ['http://example.com.', ['example.com:80'], true],
            ['https://example.com.', ['example.com:443'], true],
            ['http://example.com..', ['example.com'], false],
            ['http://.', ['*'], false],
            ['http://notexample.com', ['example.com'], false],
            ['http://example.com:8080', ['example.com:8080'], true],
            ['http://example.com:8081', ['example.com:8080'], false],
            ['http://foo.example.com:8080', ['example.com:8080'], true],
            ['http://foo.example.com:8080', ['.example.com:8080'], true],
            ['http://foo.example.com:8080', ['.EXAMPLE.com:8080'], true],
            ['http://foo.example.com:8081', ['.EXAMPLE.com:8080'], false],
            ['http://example.com:8080', ['.example.com:8080'], true],
            ['http://127.0.0.1', ['127.0.0.1'], true],
            ['http://127.0.0.1:8080', ['127.0.0.1:8080'], true],
            ['http://127.0.0.1:8081', ['127.0.0.1:8080'], false],
            ['http://127.0.0.1', ['.127.0.0.1'], true],
            ['http://127.0.0.1.', ['127.0.0.1'], false],
            ['http://127.0.0.1.', ['127.0.0.0/8'], false],
            ['http://[::1]:8080', ['[::1]:8080'], true],
            ['http://[::1]:8081', ['[::1]:8080'], false],
            ['http://[::1]', ['[::1]:80'], true],
            ['https://[::1]', ['[::1]:443'], true],
            ['http://[::1]', ['::1:80'], false],
            ['http://[::1]', ['[::1].'], false],
            ['http://[0:0:0:0:0:0:0:1]', ['::1'], true],
            ['http://[::1]', ['[0:0:0:0:0:0:0:1]'], true],
            ['http://[::1]:8080', ['[0:0:0:0:0:0:0:1]:8080'], true],
            ['http://[::1]:8081', ['[0:0:0:0:0:0:0:1]:8080'], false],
            ['http://[::1:80]', ['::1:80'], true],
            ['http://[::1]', ['.[::1]'], true],
            ['http://test.test.com', ['*.test.com'], false],
            ['http://127.0.0.1', ['127.0.0.*'], false],
            ['http://0', ['0'], true],
            ['http://anything.test', ['*'], true],
            ['http://example.com', ['*:80'], true],
            ['https://example.com', ['*:80'], false],
            ['http://example.com:8080', ['*:80'], false],
            ['http://example.com:80', ['example.com:00080'], true],
            ['http://example.com:65535', ['example.com:65535'], true],
            ['http://example.com', ['.*'], true],
            ['http://example.com', ['.*:80'], true],
            ['https://example.com', ['.*:80'], false],
            ['http://example.com', ['example.com:abc'], false],
            ['http://example.com', ['example.com:99999'], false],
            ['http://example.com', ['example.com:999999999999999999999999'], false],
            ['http://example.com', ["foo\0bar"], false],
            ['http://127.0.0.1', ["127.0.0.1\0evil"], false],
            ['http://[::1]', ["::1\0evil"], false],
            ['http://192.168.1.10', ['192.168.0.0/16'], true],
            ['http://192.168.255.255', ['192.168.0.0/16'], true],
            ['http://192.169.1.10', ['192.168.0.0/16'], false],
            ['http://192.169.0.0', ['192.168.0.0/16'], false],
            ['http://127.0.0.1', ['127.0.0.0/8'], true],
            ['http://10.1.2.3:8080', ['10.0.0.0/8'], true],
            ['http://10.1.2.3', ['.10.0.0.0/8'], true],
            ['http://[fd00::1]', ['fd00::/8'], true],
            ['http://[fd00::1]', ['[fd00::]/8'], true],
            ['http://[fdff:ffff::1]', ['fd00::/8'], true],
            ['http://[fe00::1]', ['fd00::/8'], false],
            ['http://[fe80::1]', ['fe80::/10'], true],
            ['http://[febf::1]', ['fe80::/10'], true],
            ['http://[fec0::1]', ['fe80::/10'], false],
            ['http://example.com', ['example.com/24'], false],
            ['http://127.0.0.1', ['127.0.0.0/999999999999999999999999'], false],
            ['http://192.168.1.10', ['192.168.0.0/33'], false],
            ['http://[fd00::1]', ['fd00::/129'], false],
            ['http://192.168.1.10', ['192.168.0.0/foo'], false],
            ['http://192.168.1.10:8080', ['192.168.0.0/16:8080'], false],
            ['http://[fd00::1]:8080', ['fd00::/8:8080'], false],
            ['http://[fd00::1]:8080', ['[fd00::]/8:8080'], false],
            ['http://192.168.1.10', ['fd00::/8'], false],
            ['http://[fd00::1]', ['192.168.0.0/16'], false],
            ['http://192.168.1.10', ["192.168.0.0\0evil/16"], false],
            ['http://203.0.113.10', ['0.0.0.0/0'], true],
            ['http://203.0.113.10', ['203.0.113.10/32'], true],
            ['http://203.0.113.11', ['203.0.113.10/32'], false],
            ['http://[2001:db8::1]', ['::/0'], true],
            ['http://[2001:db8::1]', ['2001:db8::1/128'], true],
            ['http://[2001:db8::2]', ['2001:db8::1/128'], false],
            ['/relative-path', ['*'], false],
        ];
    }

    /**
     * @dataProvider uriNoProxyProvider
     */
    public function testChecksUriNoProxyList(string $uri, array $list, bool $result): void
    {
        self::assertSame($result, ProxyOptions::isUriInNoProxy(Psr7\Utils::uriFor($uri), $list));
    }

    public function testEnsuresNoProxyCheckHostIsSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProxyOptions::isHostInNoProxy('', []);
    }

    public static function validProxyProvider(): array
    {
        return [
            'http with port' => ['http://proxy.example.com:8080', 'http'],
            'https' => ['https://proxy.example.com:3128', 'https'],
            'uppercase scheme is lowercased' => ['HTTP://proxy.example.com', 'http'],
            'socks5' => ['socks5://proxy.example.com:1080', 'socks5'],
            'scheme-less is http' => ['127.0.0.1:8125', 'http'],
            'scheme-less with credentials' => ['user:pass@127.0.0.1:8125', 'http'],
            'credentials in url' => ['http://user:pass@proxy.example.com:8080', 'http'],
            'ipv6 literal' => ['http://[::1]:8080', 'http'],
            'zero port left to the transport' => ['http://proxy.example.com:0', 'http'],
            'single trailing slash tolerated' => ['http://proxy.example.com:8080/', 'http'],
            'dangling colon is no port' => ['http://proxy.example.com:', 'http'],
            'scheme-less dangling colon is no port' => ['proxy.example.com:', 'http'],
            'ipv6 literal without port' => ['http://[::1]', 'http'],
        ];
    }

    /**
     * @dataProvider validProxyProvider
     */
    public function testProxySchemeReturnsLowercasedSchemeForValidProxies(string $proxy, string $expected): void
    {
        self::assertSame($expected, ProxyOptions::proxyScheme($proxy));
    }

    public static function malformedProxyProvider(): array
    {
        return [
            'leading space before scheme' => [' https://proxy.example.com:3128'],
            'non-breaking space before scheme' => ["\u{00A0}https://proxy.example.com:3128"],
            'space inside scheme' => ['ht tps://proxy.example.com:3128'],
            'empty scheme' => ['://proxy.example.com:3128'],
            'space in host' => ['http://exa mple.com:3128'],
            'leading space, scheme-less' => [' 127.0.0.1:8125'],
            'port out of range' => ['http://127.0.0.1:99999999'],
            'non-numeric port' => ['http://127.0.0.1:8a'],
            'path after authority' => ['http://proxy.example.com:8080/path'],
            'query after authority' => ['http://proxy.example.com:8080?x=1'],
            'fragment after authority' => ['http://proxy.example.com:8080#frag'],
            'slash before the userinfo @' => ['http://user/path@proxy.example.com:8080'],
            'query before the userinfo @' => ['http://user?x@proxy.example.com:8080'],
            'hash before the userinfo @' => ['http://user#x@proxy.example.com:8080'],
            'bare ipv6 without brackets' => ['http://::1:8080'],
            'empty string' => [''],
            'userinfo only, empty authority' => ['http://user@'],
            'at sign then empty authority' => ['http://@'],
            'scheme-less userinfo only' => ['user@'],
            'bare at sign' => ['@'],
            'unclosed ipv6 bracket' => ['http://[::1:8080'],
        ];
    }

    /**
     * @dataProvider malformedProxyProvider
     */
    public function testProxySchemeRejectsMalformedProxies(string $proxy): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid proxy URL.');

        ProxyOptions::proxyScheme($proxy);
    }
}
