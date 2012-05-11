<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Tests\Http\CookieJar\ArrayCookieJarTest;
use Guzzle\Http\Plugin\CookiePlugin;
use Guzzle\Http\CookieJar\ArrayCookieJar;
use Guzzle\Http\Client;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Utils;

/**
 * @group server
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

        $response = Response::fromMessage("HTTP/1.1 200 OK\r\nSet-Cookie: a=b; c=d; port=\"80,8081\"; version=1; Max-Age=86400; domain=.example.com; discard; secure;\r\n\r\n");
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
        $request->setClient(new Client());
        $request->getEventDispatcher()->addSubscriber($this->plugin);
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
            'expires' => Utils::getHttpDate('+1 day'),
            'secure' => true
        ));

        // Add a cookie that is only set on a specific port, so it wont be
        // added to the following requests
        $this->storage->save(array(
            'domain' => '.y.example.com',
            'path' => '/acme/',
            'cookie' => array('test', 'port'),
            'expires' => Utils::getHttpDate('+1 day'),
            'secure' => false,
            'port' => array(8192)
        ));

        $request1 = new Request('GET', 'https://a.y.example.com/acme/');
        $request1->setClient(new Client());
        $request2 = new Request('GET', 'https://a.y.example.com/acme/');
        $request2->setClient(new Client());
        $request3 = new Request('GET', 'http://a.y.example.com/acme/');
        $request3->setClient(new Client());
        $request4 = new Request('GET', 'http://a.y.example.com/acme/');
        $request4->setClient(new Client());

        $request1->getEventDispatcher()->addSubscriber($this->plugin);
        $request2->getEventDispatcher()->addSubscriber($this->plugin);
        $request3->getEventDispatcher()->addSubscriber($this->plugin);
        $request4->getEventDispatcher()->addSubscriber($this->plugin);

        // Set a secure cookie
        $response1 = Response::fromMessage("HTTP/1.1 200 OK\r\nSet-Cookie: a=b; c=d; Max-Age=86400; domain=.example.com; secure;\r\n\r\n");
        // Set a regular cookie
        $response2 = Response::fromMessage("HTTP/1.1 200 OK\r\nSet-Cookie: e=f h; discard; domain=.example.com;\r\n\r\n");
        $response3 = Response::fromMessage("HTTP/1.1 200 OK\r\n\r\n");

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

        $response = Response::fromMessage(
            "HTTP/1.1 200 OK\r\n" .
            "Set-Cookie: IU=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: PH=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpc=d=.Hm.yh4.1XmJWjJfs4orLQzKzPImxklQoxXSHOZATHUSEFciRueW_7704iYUtsXNEXq0M92Px2glMdWypmJ7HIQl6XIUvrZimWjQ3vIdeuRbI.FNQMAfcxu_XN1zSx7l.AcPdKL6guHc2V7hIQFhnjRW0rxm2oHY1P4bGQxFNz7f.tHm12ZD3DbdMDiDy7TBXsuP4DM-&v=2; expires=Fri, 02-Mar-2019 02:17:40 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: FPCK3=AgBNbvoQAGpGEABZLRAAbFsQAF1tEABkDhAAeO0=; expires=Sat, 02-Apr-2019 02:17:40 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: CH=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: CH=AgBNbvoQAAEcEAApuhAAMJcQADQvEAAvGxAALe0QAD6uEAATwhAAC1AQAC8t; expires=Sat, 02-Apr-2019 02:17:40 GMT; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpt=d=_e2d6jLXesxx4AoiC0W7W3YktnpITDTHoJ6vNxF7TU6JEep6Y5BFk7Z9NgHmhiXoB7jGV4uR_GBQtSDOLjflKBUVZ6UgnGmDztoj4GREK30jm1qDgReyhPv7iWaN8e8ZLpUKXtPioOzQekGha1xR8ZqGR25GT7aYQpcxaaY.2ATjTpbm7HmX8tlBIte6mYMwFpIh_krxtofGPH3R337E_aNF3illhunC5SK6I0IfZvHzBXCoxu9fjH6e0IHzyOBY656YMUIElQiDkSd8werkBIRE6LJi6YU8AWgitEpMLisOIQSkqyGiahcPFt_fsD8DmIX2YAdSeVE0KycIqd0Z9aM7mdJ3xNQ4dmOOfcZ83dDrZ.4hvuKN2jB2FQDKuxEjTVO4DmiCCSyYgcs2wh0Lc3RODVKzqAZNMTYltWMELw9JdUyDFD3EGT3ZCnH8NQ6f_AAWffyj92ZMLYfWJnXHSG.DTKlVHj.IsihVT73QzrfoMFIs&v=1; path=/; domain=127.0.0.1\r\n" .
            "Set-Cookie: fpps=deleted; expires=Wed, 03-Mar-2010 02:17:39 GMT; path=/; domain=127.0.0.1\r\n" .
            "set-cookie: fpc_s=d=ng6sEJk.1XnLUt1pfJ2kiUon07QEppAUuwW3nk0tYwcHMQ1CijnSGVZHfgvWSXQxE5eW_1hjvDAA4Nu0CSSn2xk9_.DOkKI_fZLLLUrm0hJ41VMbSUTrklw.u5IlTM5JCeK_PDjSjZNkvHMbNYziu8vwd8fMnbecf9bSo3eDDv1boowyLFk_9mnGYBeSI4U86mnm.mnfOHMARxzL6BVMTAblIAml65cR486SHzPVO6KNYvkqh8zP3m0hVIkRaPhzvDjQkDG28HCbMjq745QR2FcCmI4TNJbk7EtJmsBrlL8wvVyX5DiBmP9W990-&v=2; path=/; domain=127.0.0.1\r\n" .
            "Content-Length: 0\r\n\r\n"
        );

        $this->getServer()->enqueue(array(
            (string) $response,
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\rn"
        ));

        $request = RequestFactory::getInstance()->create('GET', $this->getServer()->getUrl());
        $request->getEventDispatcher()->addSubscriber($this->plugin);
        $request->setClient(new Client());

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
     * @covers Guzzle\Http\Plugin\CookiePlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\CookiePlugin::onRequestReceiveStatusLine
     * @covers Guzzle\Http\Plugin\CookiePlugin::onRequestBeforeSend
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

        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($this->plugin);

        $request = $client->get();
        $request->send();

        $request = $client->get();
        $request->send();

        $this->assertEquals('test=583551', $request->getHeader('Cookie'));
    }

    /**
     * @covers Guzzle\Http\Plugin\CookiePlugin::onRequestBeforeSend
     */
    public function testCookiesAreNotAddedWhenParamIsSet()
    {
        $this->storage->clear();
        $this->storage->save(array(
            'domain' => 'example.com',
            'path' => '/',
            'cookie' => array('test', 'hi'),
            'expires' => Utils::getHttpDate('+1 day')
        ));

        $client = new Client('http://example.com');
        $client->getEventDispatcher()->addSubscriber($this->plugin);

        $request = $client->get();
        $request->setResponse(new Response(200), true);
        $request->send();
        $this->assertEquals('hi', $request->getCookie()->get('test'));

        $request = $client->get();
        $request->getParams()->set('cookies.disable', true);
        $request->setResponse(new Response(200), true);
        $request->send();
        $this->assertNull($request->getCookie()->get('test'));
    }
}
