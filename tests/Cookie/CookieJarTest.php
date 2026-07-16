<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Cookie;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * @covers \GuzzleHttp\Cookie\CookieJar
 */
class CookieJarTest extends TestCase
{
    private CookieJar $jar;

    public function setUp(): void
    {
        $this->jar = new CookieJar();
    }

    protected function getTestCookies(): array
    {
        return [
            new SetCookie(['Name' => 'foo',  'Value' => 'bar', 'Domain' => 'foo.com', 'Path' => '/',    'Discard' => true]),
            new SetCookie(['Name' => 'test', 'Value' => '123', 'Domain' => 'baz.com', 'Path' => '/foo', 'Expires' => 2]),
            new SetCookie(['Name' => 'you',  'Value' => '123', 'Domain' => 'bar.com', 'Path' => '/boo', 'Expires' => \time() + 1000]),
        ];
    }

    public function testCanonicalizesIpv6HostIdentitiesAcrossSpellings(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('[2001:0DB8:0:0:0:0:0:1]');
        $uri->method('getPath')->willReturn('/');
        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $jar = new CookieJar();
        $jar->extractCookies($request, new Response(200, ['Set-Cookie' => 'a=b']));

        $cookies = $jar->toArray();
        self::assertCount(1, $cookies);
        self::assertSame('[2001:db8::1]', $cookies[0]['Domain']);

        $send = new Request('GET', 'http://[2001:db8::1]/');
        self::assertSame('a=b', $jar->withCookieHeader($send)->getHeaderLine('Cookie'));
    }

    public function testCoalescesEquivalentBareIpv6CookieDomains(): void
    {
        $jar = new CookieJar(false, [
            [
                'Name' => 'foo',
                'Value' => 'bar',
                'Domain' => '2001:0DB8:0:0:0:0:0:1',
            ],
            [
                'Name' => 'foo',
                'Value' => 'baz',
                'Domain' => '2001:db8::1',
            ],
        ]);

        $cookies = $jar->toArray();
        self::assertCount(1, $cookies);
        self::assertSame('2001:db8::1', $cookies[0]['Domain']);
        self::assertSame('baz', $cookies[0]['Value']);
    }

    public function testDoesNotEmitOrClearCookiesForLiteralLikeSuffixHosts(): void
    {
        $jar = new CookieJar(false, [
            [
                'Name' => 'foo',
                'Value' => 'bar',
                'Domain' => '[v1.ab]',
            ],
        ]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn('http');
        $uri->method('getHost')->willReturn('x.[v1.ab]');
        $uri->method('getPath')->willReturn('/');
        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->expects(self::never())->method('withHeader');

        self::assertSame($request, $jar->withCookieHeader($request));

        $jar->clear('x.[v1.ab]');
        self::assertCount(1, $jar);
        $jar->clear('[v1.AB]');
        self::assertCount(0, $jar);
    }

    public function testDoesNotEmitCookiesToHexadecimalNumericSuffixHosts(): void
    {
        $jar = new CookieJar(false, [
            [
                'Name' => 'foo',
                'Value' => 'bar',
                'Domain' => '0x7f000001',
            ],
        ]);

        $same = new Request('GET', 'http://0x7f000001/');
        self::assertSame('foo=bar', $jar->withCookieHeader($same)->getHeaderLine('Cookie'));

        $prefixed = new Request('GET', 'http://evil.0x7f000001/');
        self::assertSame('', $jar->withCookieHeader($prefixed)->getHeaderLine('Cookie'));
    }

    public function testCreatesFromArray(): void
    {
        $jar = CookieJar::fromArray([
            'foo' => 'bar',
            'baz' => 'bam',
        ], 'example.com');
        self::assertCount(2, $jar);
    }

    public function testCreatesFromArrayWithScalarNamesAndValues(): void
    {
        $jar = CookieJar::fromArray([
            1 => 0,
            'enabled' => true,
        ], 'example.com');

        $numeric = $jar->getCookieByName('1');
        $enabled = $jar->getCookieByName('enabled');

        self::assertInstanceOf(SetCookie::class, $numeric);
        self::assertSame('0', $numeric->getValue());
        self::assertInstanceOf(SetCookie::class, $enabled);
        self::assertSame('1', $enabled->getValue());
    }

    public function testRejectsNonScalarCookieValuesFromArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CookieJar::fromArray(['foo' => []], 'example.com');
    }

    public function testEmptyJarIsCountable(): void
    {
        self::assertCount(0, new CookieJar());
    }

    public function testGetsCookiesByName(): void
    {
        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->setCookie($cookie);
        }

        $testCookie = $cookies[0];
        self::assertEquals($testCookie, $this->jar->getCookieByName($testCookie->getName()));
        self::assertNull($this->jar->getCookieByName('doesnotexist'));
        self::assertNull($this->jar->getCookieByName(''));
    }

    public function testGetsCookiesByNameCaseSensitively(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'SID',
            'Value' => 'upper',
            'Domain' => 'example.com',
        ]));
        $this->jar->setCookie(new SetCookie([
            'Name' => 'sid',
            'Value' => 'lower',
            'Domain' => 'example.com',
        ]));

        self::assertSame('upper', $this->jar->getCookieByName('SID')->getValue());
        self::assertSame('lower', $this->jar->getCookieByName('sid')->getValue());
        self::assertNull($this->jar->getCookieByName('sId'));
    }

    /**
     * Provides test data for cookie cookieJar retrieval
     */
    public static function getCookiesDataProvider(): array
    {
        return [
            [['foo', 'baz', 'test', 'muppet', 'googoo'], '', '', '', false],
            [['foo', 'baz', 'muppet', 'googoo'], '', '', '', true],
            [['googoo'], 'www.example.com', '', '', false],
            [['muppet', 'googoo'], 'test.y.example.com', '', '', false],
            [['foo', 'baz'], 'example.com', '', '', false],
            [['muppet'], 'x.y.example.com', '/acme/', '', false],
            [['muppet'], 'x.y.example.com', '/acme/test/', '', false],
            [['googoo'], 'x.y.example.com', '/test/acme/test/', '', false],
            [['foo', 'baz'], 'example.com', '', '', false],
            [['baz'], 'example.com', '', 'baz', false],
        ];
    }

    public function testStoresAndRetrievesCookies(): void
    {
        $cookies = $this->getTestCookies();
        foreach ($cookies as $cookie) {
            self::assertTrue($this->jar->setCookie($cookie));
        }

        self::assertCount(3, $this->jar);
        self::assertCount(3, $this->jar->getIterator());
        self::assertEquals($cookies, $this->jar->getIterator()->getArrayCopy());
    }

    public function testRemovesTemporaryCookies(): void
    {
        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->setCookie($cookie);
        }
        $this->jar->clearSessionCookies();
        self::assertEquals(
            [$cookies[1], $cookies[2]],
            $this->jar->getIterator()->getArrayCopy()
        );
    }

    public function testRemovesSelectively(): void
    {
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->setCookie($cookie);
        }

        // Remove foo.com cookies
        $this->jar->clear('foo.com');
        self::assertCount(2, $this->jar);
        // Try again, removing no further cookies
        $this->jar->clear('foo.com');
        self::assertCount(2, $this->jar);

        // Remove bar.com cookies with path of /boo
        $this->jar->clear('bar.com', '/boo');
        self::assertCount(1, $this->jar);

        // Remove cookie by name
        $this->jar->clear('baz.com', '/foo', 'test');
        self::assertCount(0, $this->jar);
    }

    public function testDeletesCookieNamedZero(): void
    {
        $jar = new CookieJar();
        $jar->setCookie(new SetCookie([
            'Name' => '0',
            'Value' => 'zero',
            'Domain' => 'bar.com',
            'Path' => '/boo',
        ]));
        $jar->setCookie(new SetCookie([
            'Name' => 'other',
            'Value' => '123',
            'Domain' => 'bar.com',
            'Path' => '/boo',
        ]));

        $jar->clear('bar.com', '/boo', '0');

        $names = \array_map(static function (SetCookie $cookie): ?string {
            return $cookie->getName();
        }, $jar->getIterator()->getArrayCopy());

        self::assertSame(['other'], $names);
    }

    public static function falsyCookiePathProvider(): array
    {
        return [['0'], ['']];
    }

    /**
     * @dataProvider falsyCookiePathProvider
     */
    public function testClearsFalsyPathAsProvided(string $path): void
    {
        $jar = new CookieJar();
        $jar->setCookie(new SetCookie([
            'Name' => 'target',
            'Value' => '123',
            'Domain' => 'bar.com',
            'Path' => $path,
        ]));
        $jar->setCookie(new SetCookie([
            'Name' => 'other-path',
            'Value' => '123',
            'Domain' => 'bar.com',
            'Path' => '/boo',
        ]));
        $jar->setCookie(new SetCookie([
            'Name' => 'other-domain',
            'Value' => '123',
            'Domain' => 'baz.com',
            'Path' => $path,
        ]));

        $jar->clear('bar.com', $path);

        $names = \array_map(static function (SetCookie $cookie): ?string {
            return $cookie->getName();
        }, $jar->getIterator()->getArrayCopy());

        self::assertSame(['other-path', 'other-domain'], $names);
    }

    public function testClearWithNumericStringPathKeepsDistinctPathCookie(): void
    {
        $jar = new CookieJar();
        $jar->setCookie(new SetCookie([
            'Name' => 'zero-exponent-path',
            'Value' => 'zero',
            'Domain' => 'bar.com',
            'Path' => '0e0',
        ]));
        $jar->setCookie(new SetCookie([
            'Name' => 'zero-path',
            'Value' => 'zero',
            'Domain' => 'bar.com',
            'Path' => '0',
        ]));

        $jar->clear('bar.com', '0');

        self::assertCount(1, $jar);
        self::assertInstanceOf(SetCookie::class, $jar->getCookieByName('zero-exponent-path'));
        self::assertNull($jar->getCookieByName('zero-path'));
    }

    public static function domainClearProvider(): array
    {
        return [
            ['example.com', null, null],
            ['example.com', '/', null],
            ['example.com', '/', 'domain-cookie'],
        ];
    }

    /**
     * @dataProvider domainClearProvider
     */
    public function testClearingDomainDoesNotRemoveCookieWithoutDomain(string $domain, ?string $path, ?string $name): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'domainless-cookie',
            'Value' => 'value',
        ]));
        $this->jar->setCookie(new SetCookie([
            'Name' => 'domain-cookie',
            'Value' => 'value',
            'Domain' => 'example.com',
            'Path' => '/',
        ]));

        $this->jar->clear($domain, $path, $name);

        self::assertCount(1, $this->jar);
        $cookie = $this->jar->getCookieByName('domainless-cookie');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertNull($cookie->getDomain());
    }

    public function testInvalidCookieWithoutDomainDoesNotClearJar(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'session',
            'Value' => 'value',
            'Domain' => 'example.com',
        ]));

        self::assertFalse($this->jar->setCookie(new SetCookie(['Name' => 'session'])));
        self::assertCount(1, $this->jar);
    }

    public function testInvalidCookieWithEmptyDomainDoesNotClearJar(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'session',
            'Value' => 'value',
            'Domain' => 'example.com',
        ]));

        self::assertFalse($this->jar->setCookie(new SetCookie([
            'Name' => 'session',
            'Domain' => '',
        ])));
        self::assertCount(1, $this->jar);
    }

    public static function providesIncompleteCookies(): array
    {
        return [
            [
                [],
            ],
            [
                [
                    'Name' => 'foo',
                ],
            ],
            [
                [
                    'Name' => 'foo',
                    'Domain' => 'foo.com',
                ],
            ],
        ];
    }

    /**
     * @dataProvider providesIncompleteCookies
     */
    public function testDoesNotAddIncompleteCookies(array $cookie): void
    {
        self::assertFalse($this->jar->setCookie(new SetCookie($cookie)));
    }

    public static function providesEmptyCookies(): array
    {
        return [
            [
                [
                    'Name' => '',
                    'Domain' => 'foo.com',
                    'Value' => '0',
                ],
            ],
            [
                [
                    'Name' => null,
                    'Domain' => 'foo.com',
                    'Value' => '0',
                ],
            ],
        ];
    }

    /**
     * @dataProvider providesEmptyCookies
     */
    public function testDoesNotAddEmptyCookies(array $cookie): void
    {
        self::assertFalse($this->jar->setCookie(new SetCookie($cookie)));
    }

    public static function providesValidCookies(): array
    {
        return [
            [
                [
                    'Name' => '0',
                    'Domain' => 'foo.com',
                    'Value' => '0',
                ],
            ],
            [
                [
                    'Name' => 'foo',
                    'Domain' => 'foo.com',
                    'Value' => '0',
                ],
            ],
            [
                [
                    'Name' => 'foo',
                    'Domain' => 'foo.com',
                    'Value' => '0.0',
                ],
            ],
            [
                [
                    'Name' => 'foo',
                    'Domain' => 'foo.com',
                    'Value' => '0',
                ],
            ],
        ];
    }

    /**
     * @dataProvider providesValidCookies
     */
    public function testDoesAddValidCookies(array $cookie): void
    {
        self::assertTrue($this->jar->setCookie(new SetCookie($cookie)));
    }

    public function testAcceptsCookieWithoutDomain(): void
    {
        $jar = new CookieJar(true);
        $cookie = new SetCookie([
            'Name' => 'test',
            'Value' => 'value',
        ]);

        self::assertTrue($jar->setCookie($cookie));
        self::assertCount(1, $jar);
        self::assertNull($jar->toArray()[0]['Domain']);
    }

    public function testDoesNotSendCookieWithoutDomainToRequests(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'test',
            'Value' => 'value',
        ]));

        $request = $this->jar->withCookieHeader(new Request('GET', 'https://example.com/'));

        self::assertFalse($request->hasHeader('Cookie'));
    }

    public function testOverwritesCookiesThatAreOlderOrDiscardable(): void
    {
        $t = \time() + 1000;
        $data = [
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => '.example.com',
            'Path' => '/',
            'Max-Age' => 86400,
            'Secure' => true,
            'Discard' => true,
            'Expires' => $t,
        ];

        // Make sure that the discard cookie is overridden with the non-discard
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));
        self::assertCount(1, $this->jar);

        $data['Discard'] = false;
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));
        self::assertCount(1, $this->jar);

        $c = $this->jar->getIterator()->getArrayCopy();
        self::assertFalse($c[0]->getDiscard());

        // Make sure it doesn't duplicate the cookie
        $this->jar->setCookie(new SetCookie($data));
        self::assertCount(1, $this->jar);

        // Ensure the later effective expiration date supersedes the other
        $data['Expires'] = \time() + 2000;
        $data['Max-Age'] = 86401;
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));
        self::assertCount(1, $this->jar);
        $c = $this->jar->getIterator()->getArrayCopy();
        self::assertNotEquals($t, $c[0]->getExpires());
    }

    public function testOverwritesCookiesThatHaveChanged(): void
    {
        $t = \time() + 1000;
        $data = [
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => '.example.com',
            'Path' => '/',
            'Max-Age' => 86400,
            'Secure' => true,
            'Discard' => true,
            'Expires' => $t,
        ];

        // Make sure that the discard cookie is overridden with the non-discard
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));

        $data['Value'] = 'boo';
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));
        self::assertCount(1, $this->jar);

        // Changing the value plus a parameter also must overwrite the existing one
        $data['Value'] = 'zoo';
        $data['Secure'] = false;
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));
        self::assertCount(1, $this->jar);

        $c = $this->jar->getIterator()->getArrayCopy();
        self::assertSame('zoo', $c[0]->getValue());
    }

    public function testDistinctNumericStringCookieNamesCoexist(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => '0',
            'Value' => 'zero',
            'Domain' => 'example.com',
            'Path' => '/',
        ]));
        $this->jar->setCookie(new SetCookie([
            'Name' => '00',
            'Value' => 'double-zero',
            'Domain' => 'example.com',
            'Path' => '/',
        ]));

        self::assertCount(2, $this->jar);
        self::assertSame('zero', $this->jar->getCookieByName('0')->getValue());
        self::assertSame('double-zero', $this->jar->getCookieByName('00')->getValue());
    }

    public function testDeletingNumericCookieDoesNotRemoveDistinctNumericName(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => '0',
            'Value' => 'zero',
            'Domain' => 'example.com',
            'Path' => '/',
        ]));
        $this->jar->setCookie(new SetCookie([
            'Name' => '00',
            'Value' => 'double-zero',
            'Domain' => 'example.com',
            'Path' => '/',
        ]));

        self::assertFalse($this->jar->setCookie(new SetCookie([
            'Name' => '00',
            'Value' => null,
            'Domain' => 'example.com',
            'Path' => '/',
        ])));

        self::assertCount(1, $this->jar);
        self::assertSame('zero', $this->jar->getCookieByName('0')->getValue());
        self::assertNull($this->jar->getCookieByName('00'));
    }

    public function testNullValueCookieStillDeletesMatchingCookie(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'sid',
            'Value' => 'abc',
            'Domain' => 'example.com',
            'Path' => '/',
        ]));

        self::assertFalse($this->jar->setCookie(new SetCookie([
            'Name' => 'sid',
            'Value' => null,
            'Domain' => 'example.com',
            'Path' => '/',
        ])));

        self::assertCount(0, $this->jar);
    }

    public function testAddsCookiesFromResponseWithRequest(): void
    {
        $response = new Response(200, [
            'Set-Cookie' => 'fpc=d=.Hm.yh4.1XmJWjJfs4orLQzKzPImxklQoxXSHOZATHUSEFciRueW_7704iYUtsXNEXq0M92Px2glMdWypmJ7HIQl6XIUvrZimWjQ3vIdeuRbI.FNQMAfcxu_XN1zSx7l.AcPdKL6guHc2V7hIQFhnjRW0rxm2oHY1P4bGQxFNz7f.tHm12ZD3DbdMDiDy7TBXsuP4DM-&v=2; expires=Fri, 02-Mar-2019 02:17:40 GMT;',
        ]);
        $request = new Request('GET', 'http://www.example.com');
        $this->jar->extractCookies($request, $response);
        self::assertCount(1, $this->jar);

        $cookie = $this->jar->getCookieByName('fpc');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame('www.example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());
    }

    public function testExtractsCookieWithoutDomainAsHostOnly(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Path=/'])
        );

        $cookie = $this->jar->getCookieByName('sid');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame('example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());
    }

    public function testExtractsCookieWithHugeMaxAge(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Max-Age='.\PHP_INT_MAX.'; Path=/'])
        );

        $cookie = $this->jar->getCookieByName('sid');

        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame(\PHP_INT_MAX, $cookie->getExpires());
    }

    /**
     * @dataProvider expiredMaxAgeProvider
     */
    public function testDoesNotStoreExpiredMaxAgeCookieFromResponse(int $maxAge): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Max-Age='.$maxAge.'; Path=/'])
        );

        self::assertCount(0, $this->jar);
    }

    /**
     * @dataProvider expiredMaxAgeProvider
     */
    public function testExpiredMaxAgeCookieFromResponseRemovesExistingCookie(int $maxAge): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Path=/'])
        );
        self::assertCount(1, $this->jar);

        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Max-Age='.$maxAge.'; Path=/'])
        );

        self::assertCount(0, $this->jar);
    }

    public static function expiredMaxAgeProvider(): array
    {
        return [
            [0],
            [-1],
        ];
    }

    public function testMaxAgeZeroCookieFromResponseOverridesFutureExpires(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Expires=Wed, 21 Oct 2037 07:28:00 GMT; Max-Age=0; Path=/'])
        );

        self::assertCount(0, $this->jar);
    }

    public function testDoesNotSendHostOnlyCookieToSubdomain(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Path=/'])
        );

        $sameHost = $this->jar->withCookieHeader(new Request('GET', 'https://example.com/'));
        $subdomain = $this->jar->withCookieHeader(new Request('GET', 'https://foo.example.com/'));

        self::assertSame('sid=abc', $sameHost->getHeaderLine('Cookie'));
        self::assertFalse($subdomain->hasHeader('Cookie'));
    }

    public function testSendsDomainCookieToSubdomain(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Domain=example.com; Path=/'])
        );

        $request = $this->jar->withCookieHeader(new Request('GET', 'https://foo.example.com/'));

        self::assertSame('sid=abc', $request->getHeaderLine('Cookie'));
    }

    public function testExtractedIpDomainCookieMatchesExactOnly(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'http://192.168.0.1/'),
            new Response(200, ['Set-Cookie' => 'sid=secret; Domain=192.168.0.1; Path=/'])
        );

        $cookie = $this->jar->getCookieByName('sid');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertFalse($cookie->getHostOnly());
        self::assertTrue($cookie->matchesDomain('192.168.0.1'));
        self::assertFalse($cookie->matchesDomain('evil.192.168.0.1'));
    }

    public function testIpDomainCookieSendPathDoesNotLeakToLookAlikeHost(): void
    {
        $jar = new CookieJar(false, [[
            'Name' => 'sid',
            'Value' => 'secret',
            'Domain' => '192.168.0.1',
            'HostOnly' => false,
            'Path' => '/',
        ]]);

        self::assertFalse($jar->withCookieHeader(
            new Request('GET', 'https://evil.192.168.0.1/')
        )->hasHeader('Cookie'));
        self::assertSame('sid=secret', $jar->withCookieHeader(
            new Request('GET', 'https://192.168.0.1/')
        )->getHeaderLine('Cookie'));
    }

    public function testBareNumericDomainCookieIsNotLeakedToLookAlikeHost(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'http://1/'),
            new Response(200, ['Set-Cookie' => 'sid=x; Domain=1; Path=/'])
        );

        self::assertCount(1, $this->jar);
        self::assertFalse($this->jar->withCookieHeader(
            new Request('GET', 'http://evil.1/')
        )->hasHeader('Cookie'));
    }

    public function testEmptyDomainAttributeCreatesHostOnlyCookie(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Domain=; Path=/'])
        );

        $cookie = $this->jar->getCookieByName('sid');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame('example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());
    }

    public function testTrailingDotDomainAttributeCreatesHostOnlyCookie(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Domain=example.com.; Path=/'])
        );

        $cookie = $this->jar->getCookieByName('sid');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame('example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());

        $sameHost = $this->jar->withCookieHeader(new Request('GET', 'https://example.com/'));
        $subdomain = $this->jar->withCookieHeader(new Request('GET', 'https://www.example.com/'));

        self::assertSame('sid=abc', $sameHost->getHeaderLine('Cookie'));
        self::assertFalse($subdomain->hasHeader('Cookie'));
    }

    public static function setCookieDomainOutcomeProvider(): array
    {
        return [
            ['.', 0],
            ['..', 0],
            ['...', 0],
            [' . ', 0],
            ['example.com.', 1],
            ['.example.com.', 1],
        ];
    }

    /**
     * @dataProvider setCookieDomainOutcomeProvider
     */
    public function testTrailingDotDomainHandlingDoesNotOverReject(string $domain, int $expectedCount): void
    {
        $jar = new CookieJar();
        $jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Domain='.$domain.'; Path=/'])
        );

        self::assertCount($expectedCount, $jar);
    }

    public function testMismatchedTrailingDotDomainAttributeCreatesHostOnlyCookie(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Domain=other.example.com.; Path=/'])
        );

        $cookie = $this->jar->getCookieByName('sid');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame('example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());

        $subdomain = $this->jar->withCookieHeader(new Request('GET', 'https://www.example.com/'));
        self::assertFalse($subdomain->hasHeader('Cookie'));
    }

    public static function dotOnlySetCookieDomainProvider(): array
    {
        return [
            ['.'],
            ['..'],
            ['...'],
            [' . '],
        ];
    }

    /**
     * @dataProvider dotOnlySetCookieDomainProvider
     */
    public function testDoesNotStoreDotOnlyDomainCookiesFromResponse(string $domain): void
    {
        $jar = new CookieJar();

        $jar->extractCookies(
            new Request('GET', 'https://attacker.example/'),
            (new Response(200))->withAddedHeader(
                'Set-Cookie',
                'sid=attacker-controlled; Domain='.$domain
            )
        );

        self::assertCount(0, $jar);

        $request = $jar->withCookieHeader(new Request('GET', 'https://victim.com/'));

        self::assertFalse($request->hasHeader('Cookie'));
    }

    public function testDoesNotStoreRepeatedLeadingDotDomainCookieFromResponse(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=abc; Domain=..example.com; Path=/'])
        );

        self::assertCount(0, $this->jar);
    }

    public function testHostOnlyAndDomainCookiesWithSameNameCanCoexist(): void
    {
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=host; Path=/'])
        );
        $this->jar->extractCookies(
            new Request('GET', 'https://example.com/'),
            new Response(200, ['Set-Cookie' => 'sid=domain; Domain=example.com; Path=/'])
        );

        self::assertCount(2, $this->jar);

        $sameHost = $this->jar->withCookieHeader(new Request('GET', 'https://example.com/'));
        $subdomain = $this->jar->withCookieHeader(new Request('GET', 'https://foo.example.com/'));

        self::assertSame('sid=host; sid=domain', $sameHost->getHeaderLine('Cookie'));
        self::assertSame('sid=domain', $subdomain->getHeaderLine('Cookie'));
    }

    public function testClearingSubdomainDoesNotRemoveParentHostOnlyCookie(): void
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => 'sid',
            'Value' => 'host',
            'Domain' => 'example.com',
            'HostOnly' => true,
        ]));
        $this->jar->setCookie(new SetCookie([
            'Name' => 'sid',
            'Value' => 'domain',
            'Domain' => 'example.com',
        ]));

        $this->jar->clear('foo.example.com');

        self::assertCount(1, $this->jar);
        $request = $this->jar->withCookieHeader(new Request('GET', 'https://example.com/'));
        self::assertSame('sid=host', $request->getHeaderLine('Cookie'));
    }

    public static function getMatchingCookiesDataProvider(): array
    {
        return [
            ['https://example.com', 'foo=bar; baz=foobar'],
            ['http://example.com', ''],
            ['https://example.com:8912', 'foo=bar; baz=foobar'],
            ['https://foo.example.com', 'foo=bar; baz=foobar'],
            ['http://foo.example.com/test/acme/', 'googoo=gaga'],
        ];
    }

    /**
     * @dataProvider getMatchingCookiesDataProvider
     */
    public function testReturnsCookiesMatchingRequests(string $url, string $cookies): void
    {
        $bag = [
            new SetCookie([
                'Name' => 'foo',
                'Value' => 'bar',
                'Domain' => 'example.com',
                'Path' => '/',
                'Max-Age' => 86400,
                'Secure' => true,
            ]),
            new SetCookie([
                'Name' => 'baz',
                'Value' => 'foobar',
                'Domain' => 'example.com',
                'Path' => '/',
                'Max-Age' => 86400,
                'Secure' => true,
            ]),
            new SetCookie([
                'Name' => 'test',
                'Value' => '123',
                'Domain' => 'www.foobar.com',
                'Path' => '/path/',
                'Discard' => true,
            ]),
            new SetCookie([
                'Name' => 'muppet',
                'Value' => 'cookie_monster',
                'Domain' => '.y.example.com',
                'Path' => '/acme/',
                'Expires' => \time() + 86400,
            ]),
            new SetCookie([
                'Name' => 'googoo',
                'Value' => 'gaga',
                'Domain' => '.example.com',
                'Path' => '/test/acme/',
                'Max-Age' => 1500,
            ]),
        ];

        foreach ($bag as $cookie) {
            $this->jar->setCookie($cookie);
        }

        $request = new Request('GET', $url);
        $request = $this->jar->withCookieHeader($request);
        self::assertSame($cookies, $request->getHeaderLine('Cookie'));
    }

    public function testThrowsExceptionWithStrictMode(): void
    {
        $a = new CookieJar(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid cookie: Cookie name must not contain invalid characters: ASCII Control characters (0-31;127), space, tab and the following characters: ()<>@,;:\\"/?={}');
        $a->setCookie(new SetCookie(['Name' => "abc\n", 'Value' => 'foo', 'Domain' => 'bar']));
    }

    public function testDeletesCookiesByName(): void
    {
        $cookies = $this->getTestCookies();
        $cookies[] = new SetCookie([
            'Name' => 'other',
            'Value' => '123',
            'Domain' => 'bar.com',
            'Path' => '/boo',
            'Expires' => \time() + 1000,
        ]);
        $jar = new CookieJar();
        foreach ($cookies as $cookie) {
            $jar->setCookie($cookie);
        }
        self::assertCount(4, $jar);
        $jar->clear('bar.com', '/boo', 'other');
        self::assertCount(3, $jar);
        $names = \array_map(static function (SetCookie $c): ?string {
            return $c->getName();
        }, $jar->getIterator()->getArrayCopy());
        self::assertSame(['foo', 'test', 'you'], $names);
    }

    public function testCanConvertToAndLoadFromArray(): void
    {
        $jar = new CookieJar(true);
        foreach ($this->getTestCookies() as $cookie) {
            $jar->setCookie($cookie);
        }
        self::assertCount(3, $jar);
        $arr = $jar->toArray();
        self::assertCount(3, $arr);
        $newCookieJar = new CookieJar(false, $arr);
        self::assertCount(3, $newCookieJar);
        self::assertSame($jar->toArray(), $newCookieJar->toArray());
    }

    public function testAddsCookiesWithEmptyPathFromResponse(): void
    {
        $response = new Response(200, [
            'Set-Cookie' => "fpc=foobar; expires={$this->futureExpirationDate()}; path=;",
        ]);
        $request = new Request('GET', 'http://www.example.com');
        $this->jar->extractCookies($request, $response);
        $newRequest = $this->jar->withCookieHeader(new Request('GET', 'http://www.example.com/foo'));
        self::assertTrue($newRequest->hasHeader('Cookie'));

        $subdomainRequest = $this->jar->withCookieHeader(new Request('GET', 'http://foo.www.example.com/foo'));
        self::assertFalse($subdomainRequest->hasHeader('Cookie'));
    }

    public static function getCookiePathsDataProvider(): array
    {
        return [
            ['', '/'],
            ['/', '/'],
            ['/foo', '/'],
            ['/foo/bar', '/foo'],
            ['/foo/bar/', '/foo/bar'],
        ];
    }

    /**
     * @dataProvider getCookiePathsDataProvider
     */
    public function testCookiePathWithEmptySetCookiePath(string $uriPath, string $cookiePath): void
    {
        $response = (new Response(200))
            ->withAddedHeader(
                'Set-Cookie',
                "foo=bar; expires={$this->futureExpirationDate()}; domain=www.example.com; path=;"
            )
            ->withAddedHeader(
                'Set-Cookie',
                "bar=foo; expires={$this->futureExpirationDate()}; domain=www.example.com; path=foobar;"
            )
        ;
        $request = (new Request('GET', "https://www.example.com{$uriPath}"));
        $this->jar->extractCookies($request, $response);

        self::assertSame($cookiePath, $this->jar->toArray()[0]['Path']);
        self::assertSame($cookiePath, $this->jar->toArray()[1]['Path']);
    }

    public static function getDomainMatchesProvider(): array
    {
        return [
            ['www.example.com', 'www.example.com', true],
            ['www.example.com', 'www.EXAMPLE.com', true],
            ['www.example.com', 'www.example.net', false],
            ['www.example.com', 'ftp.example.com', false],
            ['www.example.com', 'example.com', true],
            ['www.example.com', 'EXAMPLE.com', true],
            ['fra.de.example.com', 'EXAMPLE.com', true],
            ['www.EXAMPLE.com', 'www.example.com', true],
            ['www.EXAMPLE.com', 'www.example.COM', true],
            ['evil.192.168.0.1', '192.168.0.1', false],
            ['evil.1', '1', false],
            ['192.168.0.1', '192.168.0.1', true],
        ];
    }

    /**
     * @dataProvider getDomainMatchesProvider
     */
    public function testIgnoresCookiesForMismatchingDomains(string $requestHost, string $domainAttribute, bool $matches): void
    {
        $response = (new Response(200))
            ->withAddedHeader(
                'Set-Cookie',
                "foo=bar; expires={$this->futureExpirationDate()}; domain={$domainAttribute}; path=/;"
            )
        ;
        $request = (new Request('GET', "https://{$requestHost}/"));
        $this->jar->extractCookies($request, $response);

        self::assertCount($matches ? 1 : 0, $this->jar->toArray());
    }

    private function futureExpirationDate(): string
    {
        return (new DateTimeImmutable())->add(new DateInterval('P1D'))->format(DateTime::COOKIE);
    }
}
