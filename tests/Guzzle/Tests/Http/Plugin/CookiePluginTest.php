<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Tests\Http\CookieJar\ArrayCookieJarTest;
use Guzzle\Http\Plugin\CookiePlugin;
use Guzzle\Http\CookieJar\ArrayCookieJar;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Guzzle\Guzzle;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CookiePluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var CookiePlugin
     */
    private $plugin;

    /**
     * @var ArrayCookieJar
     */
    private $storage;

    public function setUp()
    {
        $this->storage = new ArrayCookieJar();
        $this->plugin = new CookiePlugin($this->storage);
    }

    /**
     * Provides the parsed information from a cookie
     *
     * @return array
     */
    public function cookieParserDataProvider()
    {
        return array(
            array(
                'ASIHTTPRequestTestCookie=This+is+the+value; expires=Sat, 26-Jul-2008 17:00:42 GMT; path=/tests; domain=allseeing-i.com; PHPSESSID=6c951590e7a9359bcedde25cda73e43c; path=/";',
                array(
                    'domain' => 'allseeing-i.com',
                    'path' => '/',                    
                    'data' => array(
                        'PHPSESSID' => '6c951590e7a9359bcedde25cda73e43c'
                    ),
                    'max_age' => NULL,
                    'expires' => 'Sat, 26-Jul-2008 17:00:42 GMT',
                    'version' => NULL,
                    'secure' => NULL,
                    'discard' => NULL,
                    'port' => NULL,
                    'cookies' => array(
                        'ASIHTTPRequestTestCookie=This is the value'
                    ),
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            array('', false),
            array('foo', false),
            // Test setting a blank value for a cookie
            array(array(
                'foo=', 'foo =', 'foo =;', 'foo= ;', 'foo =', 'foo= '),
                array(
                    'cookies' => array(
                        'foo'
                    ),
                    'data' => array(),
                    'discard' => null,
                    'domain' => null,
                    'expires' => null,
                    'max_age' => null,
                    'path' => '/',
                    'port' => null,
                    'secure' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            // Test setting a value and removing quotes
            array(array(
                'foo=1', 'foo =1', 'foo =1;', 'foo=1 ;', 'foo =1', 'foo= 1', 'foo = 1 ;', 'foo="1"', 'foo="1";', 'foo= "1";'),
                array(
                    'cookies' => array(
                        'foo=1'
                    ),
                    'data' => array(),
                    'discard' => null,
                    'domain' => null,
                    'expires' => null,
                    'max_age' => null,
                    'path' => '/',
                    'port' => null,
                    'secure' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            // Test setting multiple values
            array(array(
                'foo=1; bar=2', 'foo =1; bar = "2"', 'foo=1;   bar=2'),
                array(
                    'cookies' => array(
                        'foo=1',
                        'bar=2'
                    ),
                    'data' => array(),
                    'discard' => null,
                    'domain' => null,
                    'expires' => null,
                    'max_age' => null,
                    'path' => '/',
                    'port' => null,
                    'secure' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            // Tests getting the domain and path from a reference request
            array(array(
                'foo=1; port="80,8081"; httponly', 'foo=1; port="80,8081"; domain=www.test.com; HttpOnly;', 'foo=1; ; domain=www.test.com; path=/path/; port="80,8081"; HttpOnly;'),
                array(
                    'cookies' => array(
                        'foo=1'
                    ),
                    'data' => array(),
                    'discard' => null,
                    'domain' => 'www.test.com',
                    'expires' => null,
                    'max_age' => null,
                    'path' => '/path/',
                    'port' => array('80', '8081'),
                    'secure' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => true
                ),
                'http://www.test.com/path/'
            ),
            // Some of the following tests are based on http://framework.zend.com/svn/framework/standard/trunk/tests/Zend/Http/CookieTest.php
            array(
                'justacookie=foo; domain=example.com',
                array(
                    'cookies' => array(
                        'justacookie=foo'
                    ),
                    'domain' => 'example.com',
                    'path' => '',
                    'data' => array(),
                    'discard' => null,
                    'expires' => null,
                    'max_age' => null,
                    'path' => '/',
                    'port' => null,
                    'secure' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            array(
                'expires=tomorrow; secure; path=/Space Out/; expires=Tue, 21-Nov-2006 08:33:44 GMT; domain=.example.com',
                array(
                    'cookies' => array(
                        'expires=tomorrow'
                    ),
                    'domain' => '.example.com',
                    'path' => '/Space Out/',
                    'expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'data' => array(),
                    'discard' => null,
                    'port' => null,
                    'secure' => true,
                    'version' => null,
                    'max_age' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            array(
                'domain=unittests; expires=Tue, 21-Nov-2006 08:33:44 GMT; domain=example.com; path=/some value/',
                array(
                    'cookies' => array(
                        'domain=unittests'
                    ),
                    'domain' => 'example.com',
                    'path' => '/some value/',
                    'expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'secure' => false,
                    'data' => array(),
                    'discard' => null,
                    'max_age' => null,
                    'port' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            array(
                'path=indexAction; path=/; domain=.foo.com; expires=Tue, 21-Nov-2006 08:33:44 GMT',
                array(
                    'cookies' => array(
                        'path=indexAction'
                    ),
                    'domain' => '.foo.com',
                    'path' => '/',
                    'expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'secure' => false,
                    'data' => array(),
                    'discard' => null,
                    'max_age' => null,
                    'port' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            array(
                'secure=sha1; secure; SECURE; domain=some.really.deep.domain.com; version=1; Max-Age=86400',
                array(
                    'cookies' => array(
                        'secure=sha1'
                    ),
                    'domain' => 'some.really.deep.domain.com',
                    'path' => '/',
                    'secure' => true,
                    'data' => array(),
                    'discard' => null,
                    'expires' => time() + 86400,
                    'max_age' => 86400,
                    'port' => null,
                    'version' => 1,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
            array(
                'PHPSESSID=123456789+abcd%2Cef; secure; discard; domain=.localdomain; path=/foo/baz; expires=Tue, 21-Nov-2006 08:33:44 GMT;',
                array(
                    'cookies' => array(
                        'PHPSESSID=123456789 abcd,ef'
                    ),
                    'domain' => '.localdomain',
                    'path' => '/foo/baz',
                    'expires' => 'Tue, 21-Nov-2006 08:33:44 GMT',
                    'secure' => true,
                    'data' => array(),
                    'discard' => true,
                    'max_age' => null,
                    'port' => null,
                    'version' => null,
                    'comment' => null,
                    'comment_url' => null,
                    'http_only' => false
                )
            ),
        );
    }

    /**
     * @dataProvider cookieParserDataProvider
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testParseCookie($cookie, $parsed, $url = null)
    {
        $request = null;
        if ($url) {
            $request = RequestFactory::get($url);
        }

        foreach ((array) $cookie as $c) {
            $p = CookiePlugin::parseCookie($c, $request);

            // Remove expires values from the assertion if they are relatively equal
            if ($p['expires'] != $parsed['expires']) {
                if (abs($p['expires'] - $parsed['expires']) < 20) {
                    unset($p['expires']);
                    unset($parsed['expires']);
                }
            }

            if (is_array($parsed)) {
                foreach ($parsed as $key => $value) {
                    $this->assertEquals($parsed[$key], $p[$key], 'Comparing ' . $key);
                }
                
                foreach ($p as $key => $value) {
                    $this->assertEquals($p[$key], $parsed[$key], 'Comparing ' . $key);
                }
            } else {
                $this->assertEquals($parsed, $p);
            }
        }
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testClearsCookiesWhenShuttingDown()
    {
        $this->storage->save(array(
            'domain' => '.example.com',
            'cookie' => array('a', '123')
        ));

        $this->assertEquals(1, count($this->storage->getCookies()));
        unset($this->plugin);
        $this->assertEquals(0, count($this->storage->getCookies()));
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testClearsTemporaryCookies()
    {
        $this->storage->save(array(
            'domain' => '.example.com',
            'cookie' => array('a', '123')
        ));

        $this->assertEquals(1, count($this->storage->getCookies()));
        $this->plugin->clearTemporaryCookies();
        $this->assertEquals(0, count($this->storage->getCookies()));
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testClearsCookies()
    {
        $this->assertSame($this->storage, $this->storage->save(array(
            'domain' => '.example.com',
            'cookie' => array('a', '123')
        )));
        $this->assertSame($this->storage, $this->storage->save(array(
            'domain' => 'example.com',
            'cookie' => array('b', '123')
        )));
        $this->assertEquals(2, count($this->storage->getCookies()));
        $this->assertEquals(1, $this->plugin->clearCookies('http://example.com'));
        $this->assertEquals(1, count($this->storage->getCookies()));
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testExtractsAndStoresCookies()
    {
        $cookie = array(
            array(
                'domain' => '.example.com',
                'path' => '/',
                'max_age' => '86400',
                'expires' => time() + 86400,
                'version' => '1',
                'secure' => true,
                'port' => array('80', '8081'),
                'discard' => true,
                'comment' => NULL,
                'comment_url' => NULL,
                'http_only' => false,
                'data' => array(),
                'cookie' => array('a', 'b')
            ), array (
                'domain' => '.example.com',
                'path' => '/',
                'max_age' => '86400',
                'expires' => time() + 86400,
                'version' => '1',
                'secure' => true,
                'port' => array('80','8081'),
                'discard' => true,
                'comment' => NULL,
                'comment_url' => NULL,
                'http_only' => false,
                'data' => array(),
                'cookie' =>
                array ('c', 'd')
            ),
        );

        $response = Response::factory("HTTP/1.1 200 OK\r\nSet-Cookie: a=b; c=d; port=\"80,8081\"; version=1; Max-Age=86400; domain=.example.com; discard; secure;\r\n\r\n");
        $result = $this->plugin->extractCookies($response);

        $this->assertTrue(abs($result[0]['expires'] - $cookie[0]['expires']) < 10, 'Cookie #1 expires dates are too different: ' . $result[0]['expires'] . ' vs ' . $cookie[0]['expires']);
        $this->assertTrue(abs($result[1]['expires'] - $cookie[1]['expires']) < 10, 'Cookie #2 expires dates are too different: ' . $result[1]['expires'] . ' vs ' . $cookie[1]['expires']);
        unset($cookie[0]['expires']);
        unset($cookie[1]['expires']);
        unset($result[0]['expires']);
        unset($result[1]['expires']);
        $this->assertEquals($cookie, $result);

        $this->assertEquals(2, count($this->storage->getCookies()));
        $result = $this->storage->getCookies();
        unset($result[0]['expires']);
        unset($result[1]['expires']);
        $this->assertEquals($cookie, $result);
        
        // Clear out the currently held cookies
        $this->assertEquals(2, $this->storage->clear());
        $this->assertEquals(0, count($this->storage->getCookies()));

        // Create a new request, attach the cookie plugin, set a mock response
        $request = new Request('GET', 'http://www.example.com/');
        $request->getEventManager()->attach($this->plugin);
        $request->setResponse($response, true);
        $request->send();

        // Assert that the plugin caught the cookies in the response
        $this->assertEquals(2, count($this->storage->getCookies()));
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testAddsCookiesToRequests()
    {
        ArrayCookieJarTest::addCookies($this->storage);

        $this->storage->save(array(
            'domain' => '.y.example.com',
            'path' => '/acme/',
            'cookie' => array('secure', 'sec'),
            'expires' => Guzzle::getHttpDate('+1 day'),
            'secure' => true
        ));

        // Add a cookie that is only set on a specific port, so it wont be
        // added to the following requests
        $this->storage->save(array(
            'domain' => '.y.example.com',
            'path' => '/acme/',
            'cookie' => array('test', 'port'),
            'expires' => Guzzle::getHttpDate('+1 day'),
            'secure' => false,
            'port' => array(8192)
        ));
        
        $request1 = new Request('GET', 'https://a.y.example.com/acme/');
        $request2 = new Request('GET', 'https://a.y.example.com/acme/');
        $request3 = new Request('GET', 'http://a.y.example.com/acme/');
        $request4 = new Request('GET', 'http://a.y.example.com/acme/');

        $request1->getEventManager()->attach($this->plugin);
        $request2->getEventManager()->attach($this->plugin);
        $request3->getEventManager()->attach($this->plugin);
        $request4->getEventManager()->attach($this->plugin);

        // Set a secure cookie
        $response1 = Response::factory("HTTP/1.1 200 OK\r\nSet-Cookie: a=b; c=d; Max-Age=86400; domain=.example.com; secure;\r\n\r\n");
        // Set a regular cookie
        $response2 = Response::factory("HTTP/1.1 200 OK\r\nSet-Cookie: e=f h; discard; domain=.example.com;\r\n\r\n");
        $response3 = Response::factory("HTTP/1.1 200 OK\r\n\r\n");

        $request1->setResponse($response1, true);
        $request2->setResponse($response2, true);
        $request3->setResponse($response3, true);
        
        $request1->send();
        $request2->send();
        $request3->send();

        $this->assertEquals('muppet=cookie_monster;secure=sec', (string) $request1->getCookie());
        $this->assertEquals('muppet=cookie_monster;secure=sec;a=b;c=d', (string) $request2->getCookie());
        $this->assertEquals('muppet=cookie_monster;e=f h', (string) $request3->getCookie());

        // Clear the e=f h temporary cookie
        $this->plugin->clearTemporaryCookies();
        $request4->setResponse($response3, true);
        $request4->send();
        $this->assertEquals('muppet=cookie_monster', (string) $request4->getCookie());
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin
     */
    public function testExtractsMultipleCookies()
    {
        $this->plugin->clearCookies();
        
        $response = Response::factory(
            "HTTP/1.1 200 OK\r\n" .
            "Set-Cookie: IU=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: PH=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpc=d=.Hm.yh4.1XmJWjJfs4orLQzKzPImxklQoxXSHOZATHUSEFciRueW_7704iYUtsXNEXq0M92Px2glMdWypmJ7HIQl6XIUvrZimWjQ3vIdeuRbI.FNQMAfcxu_XN1zSx7l.AcPdKL6guHc2V7hIQFhnjRW0rxm2oHY1P4bGQxFNz7f.tHm12ZD3DbdMDiDy7TBXsuP4DM-&v=2; expires=Fri, 02-Mar-2019 02:17:40 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: FPCK3=AgBNbvoQAGpGEABZLRAAbFsQAF1tEABkDhAAeO0=; expires=Sat, 02-Apr-2019 02:17:40 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: CH=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: CH=AgBNbvoQAAEcEAApuhAAMJcQADQvEAAvGxAALe0QAD6uEAATwhAAC1AQAC8t; expires=Sat, 02-Apr-2019 02:17:40 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpt=d=_e2d6jLXesxx4AoiC0W7W3YktnpITDTHoJ6vNxF7TU6JEep6Y5BFk7Z9NgHmhiXoB7jGV4uR_GBQtSDOLjflKBUVZ6UgnGmDztoj4GREK30jm1qDgReyhPv7iWaN8e8ZLpUKXtPioOzQekGha1xR8ZqGR25GT7aYQpcxaaY.2ATjTpbm7HmX8tlBIte6mYMwFpIh_krxtofGPH3R337E_aNF3illhunC5SK6I0IfZvHzBXCoxu9fjH6e0IHzyOBY656YMUIElQiDkSd8werkBIRE6LJi6YU8AWgitEpMLisOIQSkqyGiahcPFt_fsD8DmIX2YAdSeVE0KycIqd0Z9aM7mdJ3xNQ4dmOOfcZ83dDrZ.4hvuKN2jB2FQDKuxEjTVO4DmiCCSyYgcs2wh0Lc3RODVKzqAZNMTYltWMELw9JdUyDFD3EGT3ZCnH8NQ6f_AAWffyj92ZMLYfWJnXHSG.DTKlVHj.IsihVT73QzrfoMFIs&v=1; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpps=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpc_s=d=ng6sEJk.1XnLUt1pfJ2kiUon07QEppAUuwW3nk0tYwcHMQ1CijnSGVZHfgvWSXQxE5eW_1hjvDAA4Nu0CSSn2xk9_.DOkKI_fZLLLUrm0hJ41VMbSUTrklw.u5IlTM5JCeK_PDjSjZNkvHMbNYziu8vwd8fMnbecf9bSo3eDDv1boowyLFk_9mnGYBeSI4U86mnm.mnfOHMARxzL6BVMTAblIAml65cR486SHzPVO6KNYvkqh8zP3m0hVIkRaPhzvDjQkDG28HCbMjq745QR2FcCmI4TNJbk7EtJmsBrlL8wvVyX5DiBmP9W990-&v=2; path=/; domain=127.0.0.1\r\n" .
            "Content-Length: 0\r\n\r\n"
        );

        $this->getServer()->enqueue(array(
            (string) $response,
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\rn"
        ));

        $request = RequestFactory::get($this->getServer()->getUrl());
        $request->getEventManager()->attach($this->plugin);

        $request->send();
        $this->assertNull($request->getHeader('Cookie'));
        
        $request->setState('new');
        $request->send();

        $this->assertNotNull($request->getHeader('Cookie'));

        // Doesn't send expired cookies
        $this->assertEmpty($request->getCookie('IU'));
        $this->assertEmpty($request->getCookie('PH'));

        $this->assertNotEmpty($request->getCookie('fpc'));
        $this->assertNotEmpty($request->getCookie('FPCK3'));
        $this->assertNotEmpty($request->getCookie('CH'));
        $this->assertNotEmpty($request->getCookie('fpt'));
        $this->assertNotEmpty($request->getCookie('fpc_s'));
        $this->assertNotEmpty($request->getCookie('CH'));
        $this->assertNotEmpty($request->getCookie('CH'));

        $this->assertEquals(9, count($this->plugin->extractCookies($response)));
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin::update
     */
    public function testCookiesAreExtractedFromRedirectResponses()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 302 Moved Temporarily\r\n" .
            "Set-Cookie: test=583551; expires=Wednesday, 23-Mar-2050 19:49:45 GMT; path=/\r\n" .
            "Location: /redirect\r\n\r\n",
            
            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n",

            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n"
        ));

        $request = RequestFactory::get($this->getServer()->getUrl());
        $request->getEventManager()->attach($this->plugin);
        $request->send();

        $request = RequestFactory::get($this->getServer()->getUrl());
        $request->getEventManager()->attach($this->plugin);
        $request->send();

        $this->assertEquals('test=583551', $request->getHeader('Cookie'));
    }
}