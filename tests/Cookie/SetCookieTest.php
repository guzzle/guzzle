<?php
namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\TestCase;

/**
 * @covers GuzzleHttp\Cookie\SetCookie
 */
class SetCookieTest extends TestCase
{
    public function testInitializesDefaultValues()
    {
        $cookie = new SetCookie();
        self::assertSame('/', $cookie->getPath());
    }

    public function testConvertsDateTimeMaxAgeToUnixTimestamp()
    {
        $cookie = new SetCookie(['Expires' => 'November 20, 1984']);
        self::assertInternalType('integer', $cookie->getExpires());
    }

    public function testAddsExpiresBasedOnMaxAge()
    {
        $t = time();
        $cookie = new SetCookie(['Max-Age' => 100]);
        self::assertEquals($t + 100, $cookie->getExpires());
    }

    public function testHoldsValues()
    {
        $t = time();
        $data = [
            'Name'     => 'foo',
            'Value'    => 'baz',
            'Path'     => '/bar',
            'Domain'   => 'baz.com',
            'Expires'  => $t,
            'Max-Age'  => 100,
            'Secure'   => true,
            'Discard'  => true,
            'HttpOnly' => true,
            'foo'      => 'baz',
            'bar'      => 'bam'
        ];

        $cookie = new SetCookie($data);
        self::assertEquals($data, $cookie->toArray());

        self::assertSame('foo', $cookie->getName());
        self::assertSame('baz', $cookie->getValue());
        self::assertSame('baz.com', $cookie->getDomain());
        self::assertSame('/bar', $cookie->getPath());
        self::assertSame($t, $cookie->getExpires());
        self::assertSame(100, $cookie->getMaxAge());
        self::assertTrue($cookie->getSecure());
        self::assertTrue($cookie->getDiscard());
        self::assertTrue($cookie->getHttpOnly());
        self::assertSame('baz', $cookie->toArray()['foo']);
        self::assertSame('bam', $cookie->toArray()['bar']);

        $cookie->setName('a');
        $cookie->setValue('b');
        $cookie->setPath('c');
        $cookie->setDomain('bar.com');
        $cookie->setExpires(10);
        $cookie->setMaxAge(200);
        $cookie->setSecure(false);
        $cookie->setHttpOnly(false);
        $cookie->setDiscard(false);

        self::assertSame('a', $cookie->getName());
        self::assertSame('b', $cookie->getValue());
        self::assertSame('c', $cookie->getPath());
        self::assertSame('bar.com', $cookie->getDomain());
        self::assertSame(10, $cookie->getExpires());
        self::assertSame(200, $cookie->getMaxAge());
        self::assertFalse($cookie->getSecure());
        self::assertFalse($cookie->getDiscard());
        self::assertFalse($cookie->getHttpOnly());
    }

    public function testDeterminesIfExpired()
    {
        $c = new SetCookie();
        $c->setExpires(10);
        self::assertTrue($c->isExpired());
        $c->setExpires(time() + 10000);
        self::assertFalse($c->isExpired());
    }

    public function testMatchesDomain()
    {
        $cookie = new SetCookie();
        self::assertTrue($cookie->matchesDomain('baz.com'));

        $cookie->setDomain('baz.com');
        self::assertTrue($cookie->matchesDomain('baz.com'));
        self::assertFalse($cookie->matchesDomain('bar.com'));

        $cookie->setDomain('.baz.com');
        self::assertTrue($cookie->matchesDomain('.baz.com'));
        self::assertTrue($cookie->matchesDomain('foo.baz.com'));
        self::assertFalse($cookie->matchesDomain('baz.bar.com'));
        self::assertTrue($cookie->matchesDomain('baz.com'));

        $cookie->setDomain('.127.0.0.1');
        self::assertTrue($cookie->matchesDomain('127.0.0.1'));

        $cookie->setDomain('127.0.0.1');
        self::assertTrue($cookie->matchesDomain('127.0.0.1'));

        $cookie->setDomain('.com.');
        self::assertFalse($cookie->matchesDomain('baz.com'));

        $cookie->setDomain('.local');
        self::assertTrue($cookie->matchesDomain('example.local'));

        $cookie->setDomain('example.com/'); // malformed domain
        self::assertFalse($cookie->matchesDomain('example.com'));
    }

    public function pathMatchProvider()
    {
        return [
            ['/foo', '/foo', true],
            ['/foo', '/Foo', false],
            ['/foo', '/fo', false],
            ['/foo', '/foo/bar', true],
            ['/foo', '/foo/bar/baz', true],
            ['/foo', '/foo/bar//baz', true],
            ['/foo', '/foobar', false],
            ['/foo/bar', '/foo', false],
            ['/foo/bar', '/foobar', false],
            ['/foo/bar', '/foo/bar', true],
            ['/foo/bar', '/foo/bar/', true],
            ['/foo/bar', '/foo/bar/baz', true],
            ['/foo/bar/', '/foo/bar', false],
            ['/foo/bar/', '/foo/bar/', true],
            ['/foo/bar/', '/foo/bar/baz', true],
        ];
    }

    /**
     * @dataProvider pathMatchProvider
     */
    public function testMatchesPath($cookiePath, $requestPath, $isMatch)
    {
        $cookie = new SetCookie();
        $cookie->setPath($cookiePath);
        self::assertSame($isMatch, $cookie->matchesPath($requestPath));
    }

    public function cookieValidateProvider()
    {
        return [
            ['foo', 'baz', 'bar', true],
            ['0', '0', '0', true],
            ['foo[bar]', 'baz', 'bar', true],
            ['', 'baz', 'bar', 'The cookie name must not be empty'],
            ['foo', '', 'bar', 'The cookie value must not be empty'],
            ['foo', 'baz', '', 'The cookie domain must not be empty'],
            ["foo\r", 'baz', '0', 'Cookie name must not contain invalid characters: ASCII Control characters (0-31;127), space, tab and the following characters: ()<>@,;:\"/?={}'],
        ];
    }

    /**
     * @dataProvider cookieValidateProvider
     */
    public function testValidatesCookies($name, $value, $domain, $result)
    {
        $cookie = new SetCookie([
            'Name'   => $name,
            'Value'  => $value,
            'Domain' => $domain,
        ]);
        self::assertSame($result, $cookie->validate());
    }

    public function testDoesNotMatchIp()
    {
        $cookie = new SetCookie(['Domain' => '192.168.16.']);
        self::assertFalse($cookie->matchesDomain('192.168.16.121'));
    }

    public function testConvertsToString()
    {
        $t = 1382916008;
        $cookie = new SetCookie([
            'Name' => 'test',
            'Value' => '123',
            'Domain' => 'foo.com',
            'Expires' => $t,
            'Path' => '/abc',
            'HttpOnly' => true,
            'Secure' => true
        ]);
        self::assertSame(
            'test=123; Domain=foo.com; Path=/abc; Expires=Sun, 27 Oct 2013 23:20:08 GMT; Secure; HttpOnly',
            (string) $cookie
        );
    }

    /**
     * Provides the parsed information from a cookie
     *
     * @return array
     */
    public function cookieParserDataProvider()
    {
        return [
            [
                'ASIHTTPRequestTestCookie=This+is+the+value; expires=Sat, 26-Jul-2008 17:00:42 GMT; path=/tests; domain=allseeing-i.com; PHPSESSID=6c951590e7a9359bcedde25cda73e43c; path=/;',
                [
                    'Domain' => 'allseeing-i.com',
                    'Path' => '/',
                    'PHPSESSID' => '6c951590e7a9359bcedde25cda73e43c',
                    'Max-Age' => null,
                    'Expires' => 'Sat, 26-Jul-2008 17:00:42 GMT',
                    'Secure' => null,
                    'Discard' => null,
                    'Name' => 'ASIHTTPRequestTestCookie',
                    'Value' => 'This+is+the+value',
                    'HttpOnly' => false
                ]
            ],
            ['', []],
            ['foo', []],
            ['; foo', []],
            [
                'foo="bar"',
                [
                    'Name' => 'foo',
                    'Value' => '"bar"',
                    'Discard' => null,
                    'Domain' => null,
                    'Expires' => null,
                    'Max-Age' => null,
                    'Path' => '/',
                    'Secure' => null,
                    'HttpOnly' => false
                ]
            ],
            // Test setting a blank value for a cookie
            [[
                'foo=', 'foo =', 'foo =;', 'foo= ;', 'foo =', 'foo= '],
                [
                    'Name' => 'foo',
                    'Value' => '',
                    'Discard' => null,
                    'Domain' => null,
                    'Expires' => null,
                    'Max-Age' => null,
                    'Path' => '/',
                    'Secure' => null,
                    'HttpOnly' => false
                ]
            ],
            // Test setting a value and removing quotes
            [[
                'foo=1', 'foo =1', 'foo =1;', 'foo=1 ;', 'foo =1', 'foo= 1', 'foo = 1 ;'],
                [
                    'Name' => 'foo',
                    'Value' => '1',
                    'Discard' => null,
                    'Domain' => null,
                    'Expires' => null,
                    'Max-Age' => null,
                    'Path' => '/',
                    'Secure' => null,
                    'HttpOnly' => false
                ]
            ],
            // Some of the following tests are based on http://framework.zend.com/svn/framework/standard/trunk/tests/Zend/Http/CookieTest.php
            [
                'justacookie=foo; domain=example.com',
                [
                    'Name' => 'justacookie',
                    'Value' => 'foo',
                    'Domain' => 'example.com',
                    'Discard' => null,
                    'Expires' => null,
                    'Max-Age' => null,
                    'Path' => '/',
                    'Secure' => null,
                    'HttpOnly' => false
                ]
            ],
            [
                'expires=tomorrow; secure; path=/Space Out/; expires=Tue, 21-Nov-2006 08:33:44 GMT; domain=.example.com',
                [
                    'Name' => 'expires',
                    'Value' => 'tomorrow',
                    'Domain' => '.example.com',
                    'Path' => '/Space Out/',
                    'Expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'Discard' => null,
                    'Secure' => true,
                    'Max-Age' => null,
                    'HttpOnly' => false
                ]
            ],
            [
                'domain=unittests; expires=Tue, 21-Nov-2006 08:33:44 GMT; domain=example.com; path=/some value/',
                [
                    'Name' => 'domain',
                    'Value' => 'unittests',
                    'Domain' => 'example.com',
                    'Path' => '/some value/',
                    'Expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'Secure' => false,
                    'Discard' => null,
                    'Max-Age' => null,
                    'HttpOnly' => false
                ]
            ],
            [
                'path=indexAction; path=/; domain=.foo.com; expires=Tue, 21-Nov-2006 08:33:44 GMT',
                [
                    'Name' => 'path',
                    'Value' => 'indexAction',
                    'Domain' => '.foo.com',
                    'Path' => '/',
                    'Expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'Secure' => false,
                    'Discard' => null,
                    'Max-Age' => null,
                    'HttpOnly' => false
                ]
            ],
            [
                'secure=sha1; secure; SECURE; domain=some.really.deep.domain.com; version=1; Max-Age=86400',
                [
                    'Name' => 'secure',
                    'Value' => 'sha1',
                    'Domain' => 'some.really.deep.domain.com',
                    'Path' => '/',
                    'Secure' => true,
                    'Discard' => null,
                    'Expires' => time() + 86400,
                    'Max-Age' => 86400,
                    'HttpOnly' => false,
                    'version' => '1'
                ]
            ],
            [
                'PHPSESSID=123456789+abcd%2Cef; secure; discard; domain=.localdomain; path=/foo/baz; expires=Tue, 21-Nov-2006 08:33:44 GMT;',
                [
                    'Name' => 'PHPSESSID',
                    'Value' => '123456789+abcd%2Cef',
                    'Domain' => '.localdomain',
                    'Path' => '/foo/baz',
                    'Expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'Secure' => true,
                    'Discard' => true,
                    'Max-Age' => null,
                    'HttpOnly' => false
                ]
            ],
        ];
    }

    /**
     * @dataProvider cookieParserDataProvider
     */
    public function testParseCookie($cookie, $parsed)
    {
        foreach ((array) $cookie as $v) {
            $c = SetCookie::fromString($v);
            $p = $c->toArray();

            if (isset($p['Expires'])) {
                // Remove expires values from the assertion if they are relatively equal
                if (abs($p['Expires'] != strtotime($parsed['Expires'])) < 40) {
                    unset($p['Expires']);
                    unset($parsed['Expires']);
                }
            }

            if (!empty($parsed)) {
                foreach ($parsed as $key => $value) {
                    self::assertEquals($parsed[$key], $p[$key], 'Comparing ' . $key . ' ' . var_export($value, true) . ' : ' . var_export($parsed, true) . ' | ' . var_export($p, true));
                }
                foreach ($p as $key => $value) {
                    self::assertEquals($p[$key], $parsed[$key], 'Comparing ' . $key . ' ' . var_export($value, true) . ' : ' . var_export($parsed, true) . ' | ' . var_export($p, true));
                }
            } else {
                self::assertSame([
                    'Name' => null,
                    'Value' => null,
                    'Domain' => null,
                    'Path' => '/',
                    'Max-Age' => null,
                    'Expires' => null,
                    'Secure' => false,
                    'Discard' => false,
                    'HttpOnly' => false,
                ], $p);
            }
        }
    }

    /**
     * Provides the data for testing isExpired
     *
     * @return array
     */
    public function isExpiredProvider()
    {
        return [
            [
                'FOO=bar; expires=Thu, 01 Jan 1970 00:00:00 GMT;',
                true,
            ],
            [
                'FOO=bar; expires=Thu, 01 Jan 1970 00:00:01 GMT;',
                true,
            ],
            [
                'FOO=bar; expires=' . date(\DateTime::RFC1123, time()+10) . ';',
                false,
            ],
            [
                'FOO=bar; expires=' . date(\DateTime::RFC1123, time()-10) . ';',
                true,
            ],
            [
                'FOO=bar;',
                false,
            ],
        ];
    }

    /**
     * @dataProvider isExpiredProvider
     */
    public function testIsExpired($cookie, $expired)
    {
        self::assertSame(
            $expired,
            SetCookie::fromString($cookie)->isExpired()
        );
    }
}
