<?php

namespace GuzzleHttp\Tests\CookieJar;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Cookie\CookieJar
 */
class CookieJarTest extends TestCase
{
    /**
     * @var CookieJar
     */
    private $jar;

    public function setUp(): void
    {
        $this->jar = new CookieJar();
    }

    protected function getTestCookies()
    {
        return [
            new SetCookie(['Name' => 'foo',  'Value' => 'bar', 'Domain' => 'foo.com', 'Path' => '/',    'Discard' => true]),
            new SetCookie(['Name' => 'test', 'Value' => '123', 'Domain' => 'baz.com', 'Path' => '/foo', 'Expires' => 2]),
            new SetCookie(['Name' => 'you',  'Value' => '123', 'Domain' => 'bar.com', 'Path' => '/boo', 'Expires' => \time() + 1000]),
        ];
    }

    public function testCreatesFromArray()
    {
        $jar = CookieJar::fromArray([
            'foo' => 'bar',
            'baz' => 'bam',
        ], 'example.com');
        self::assertCount(2, $jar);
    }

    public function testEmptyJarIsCountable()
    {
        self::assertCount(0, new CookieJar());
    }

    public function testGetsCookiesByName()
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

    /**
     * Provides test data for cookie cookieJar retrieval
     */
    public static function getCookiesDataProvider()
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

    public function testStoresAndRetrievesCookies()
    {
        $cookies = $this->getTestCookies();
        foreach ($cookies as $cookie) {
            self::assertTrue($this->jar->setCookie($cookie));
        }

        self::assertCount(3, $this->jar);
        self::assertCount(3, $this->jar->getIterator());
        self::assertEquals($cookies, $this->jar->getIterator()->getArrayCopy());
    }

    public function testRemovesTemporaryCookies()
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

    public function testRemovesSelectively()
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
        $this->jar->clear(null, null, 'test');
        self::assertCount(0, $this->jar);
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
                    'Name' => false,
                ],
            ],
            [
                [
                    'Name' => true,
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
    public function testDoesNotAddIncompleteCookies(array $cookie)
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
                    'Value' => 0,
                ],
            ],
            [
                [
                    'Name' => null,
                    'Domain' => 'foo.com',
                    'Value' => 0,
                ],
            ],
        ];
    }

    /**
     * @dataProvider providesEmptyCookies
     */
    public function testDoesNotAddEmptyCookies(array $cookie)
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
                    'Value' => 0,
                ],
            ],
            [
                [
                    'Name' => 'foo',
                    'Domain' => 'foo.com',
                    'Value' => 0,
                ],
            ],
            [
                [
                    'Name' => 'foo',
                    'Domain' => 'foo.com',
                    'Value' => 0.0,
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
    public function testDoesAddValidCookies(array $cookie)
    {
        self::assertTrue($this->jar->setCookie(new SetCookie($cookie)));
    }

    public function testOverwritesCookiesThatAreOlderOrDiscardable()
    {
        $t = \time() + 1000;
        $data = [
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => '.example.com',
            'Path' => '/',
            'Max-Age' => '86400',
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

        // Make sure the more future-ful expiration date supersede the other
        $data['Expires'] = \time() + 2000;
        self::assertTrue($this->jar->setCookie(new SetCookie($data)));
        self::assertCount(1, $this->jar);
        $c = $this->jar->getIterator()->getArrayCopy();
        self::assertNotEquals($t, $c[0]->getExpires());
    }

    public function testOverwritesCookiesThatHaveChanged()
    {
        $t = \time() + 1000;
        $data = [
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => '.example.com',
            'Path' => '/',
            'Max-Age' => '86400',
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

    public function testAddsCookiesFromResponseWithRequest()
    {
        $response = new Response(200, [
            'Set-Cookie' => 'fpc=d=.Hm.yh4.1XmJWjJfs4orLQzKzPImxklQoxXSHOZATHUSEFciRueW_7704iYUtsXNEXq0M92Px2glMdWypmJ7HIQl6XIUvrZimWjQ3vIdeuRbI.FNQMAfcxu_XN1zSx7l.AcPdKL6guHc2V7hIQFhnjRW0rxm2oHY1P4bGQxFNz7f.tHm12ZD3DbdMDiDy7TBXsuP4DM-&v=2; expires=Fri, 02-Mar-2019 02:17:40 GMT;',
        ]);
        $request = new Request('GET', 'http://www.example.com');
        $this->jar->extractCookies($request, $response);
        self::assertCount(1, $this->jar);
    }

    public static function getMatchingCookiesDataProvider()
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
    public function testReturnsCookiesMatchingRequests(string $url, string $cookies)
    {
        $bag = [
            new SetCookie([
                'Name' => 'foo',
                'Value' => 'bar',
                'Domain' => 'example.com',
                'Path' => '/',
                'Max-Age' => '86400',
                'Secure' => true,
            ]),
            new SetCookie([
                'Name' => 'baz',
                'Value' => 'foobar',
                'Domain' => 'example.com',
                'Path' => '/',
                'Max-Age' => '86400',
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

    public function testThrowsExceptionWithStrictMode()
    {
        $a = new CookieJar(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid cookie: Cookie name must not contain invalid characters: ASCII Control characters (0-31;127), space, tab and the following characters: ()<>@,;:\\"/?={}');
        $a->setCookie(new SetCookie(['Name' => "abc\n", 'Value' => 'foo', 'Domain' => 'bar']));
    }

    public function testDeletesCookiesByName()
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
        $names = \array_map(static function (SetCookie $c) {
            return $c->getName();
        }, $jar->getIterator()->getArrayCopy());
        self::assertSame(['foo', 'test', 'you'], $names);
    }

    public function testCanConvertToAndLoadFromArray()
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

    public function testAddsCookiesWithEmptyPathFromResponse()
    {
        $response = new Response(200, [
            'Set-Cookie' => "fpc=foobar; expires={$this->futureExpirationDate()}; path=;",
        ]);
        $request = new Request('GET', 'http://www.example.com');
        $this->jar->extractCookies($request, $response);
        $newRequest = $this->jar->withCookieHeader(new Request('GET', 'http://www.example.com/foo'));
        self::assertTrue($newRequest->hasHeader('Cookie'));
    }

    public static function getCookiePathsDataProvider()
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
    public function testCookiePathWithEmptySetCookiePath(string $uriPath, string $cookiePath)
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

    public static function getDomainMatchesProvider()
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
        ];
    }

    /**
     * @dataProvider getDomainMatchesProvider
     */
    public function testIgnoresCookiesForMismatchingDomains(string $requestHost, string $domainAttribute, bool $matches)
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

    private function futureExpirationDate()
    {
        return (new DateTimeImmutable())->add(new DateInterval('P1D'))->format(DateTime::COOKIE);
    }
}
