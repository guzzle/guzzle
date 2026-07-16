<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\HostIdentity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\HostIdentity
 */
class HostIdentityTest extends TestCase
{
    public function testCanonicalizesIpv6Hosts(): void
    {
        self::assertSame('[2001:db8::1]', HostIdentity::canonicalHost('[2001:0DB8:0:0:0:0:0:1]'));
        self::assertSame('[::ffff:127.0.0.1]', HostIdentity::canonicalHost('[::FFFF:7F00:1]'));
    }

    public function testFoldsNonIpv6HostsTextually(): void
    {
        self::assertSame('example.com', HostIdentity::canonicalHost('EXAMPLE.com'));
        self::assertSame('[v1.ab]', HostIdentity::canonicalHost('[v1.AB]'));
        self::assertSame('[fe80::1%25eth0]', HostIdentity::canonicalHost('[fe80::1%25ETH0]'));
        self::assertSame('[::1', HostIdentity::canonicalHost('[::1'));
        self::assertSame('2001:0db8::1', HostIdentity::canonicalHost('2001:0DB8::1'));
    }

    public function testCanonicalizesCookieDomainsWithinTheirSyntaxClass(): void
    {
        self::assertSame('2001:db8::1', HostIdentity::canonicalCookieDomain('2001:0DB8:0:0:0:0:0:1'));
        self::assertSame('[2001:db8::1]', HostIdentity::canonicalCookieDomain('[2001:0DB8:0:0:0:0:0:1]'));
        self::assertSame('example.com', HostIdentity::canonicalCookieDomain('EXAMPLE.com'));
        self::assertSame('fe80::1%eth0', HostIdentity::canonicalCookieDomain('fe80::1%ETH0'));
        self::assertSame('[::1', HostIdentity::canonicalCookieDomain('[::1'));
    }

    public function testCanonicalizesBracketedHostHeaders(): void
    {
        self::assertSame('[2001:db8::1]:8080', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:8080'));
        self::assertSame('[2001:db8::1]', HostIdentity::canonicalHostHeader('[2001:0DB8:0:0:0:0:0:1]'));
        self::assertSame('[2001:db8::1]:0', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:0'));
        self::assertSame('[2001:db8::1]:65535', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:65535'));
    }

    public function testFoldsOtherHostHeadersTextually(): void
    {
        self::assertSame('example.com:80', HostIdentity::canonicalHostHeader('EXAMPLE.com:80'));
        self::assertSame('[::1]x', HostIdentity::canonicalHostHeader('[::1]x'));
        self::assertSame('[::0:1], [::1]', HostIdentity::canonicalHostHeader('[::0:1], [::1]'));
    }

    public function testFoldsHostHeadersWithoutOneValidPortSuffixTextually(): void
    {
        self::assertSame('[2001:0db8::1]:', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:'));
        self::assertSame('[2001:0db8::1]:abc', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:abc'));
        self::assertSame('[2001:0db8::1]:65536', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:65536'));
        self::assertSame('[2001:0db8::1]::80', HostIdentity::canonicalHostHeader('[2001:0DB8::1]::80'));
        self::assertSame('[2001:0db8::1]:80, [::2]', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:80, [::2]'));
        self::assertSame('[2001:0db8::1]:80 x', HostIdentity::canonicalHostHeader('[2001:0DB8::1]:80 x'));
    }
}
