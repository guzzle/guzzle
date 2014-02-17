<?php

namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\CookieJar\ArrayCookieJar;
use GuzzleHttp\CookieJar\SetCookie;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\Request;

/**
 * @covers GuzzleHttp\CookieJar\ArrayCookieJar
 */
class ArrayCookieJarTest extends \PHPUnit_Framework_TestCase
{
    /** @var ArrayCookieJar */
    private $jar;

    public function setUp()
    {
        $this->jar = new ArrayCookieJar();
    }

    protected function getTestCookies()
    {
        return [
            new SetCookie(['Name' => 'foo',  'Value' => 'bar', 'Domain' => 'foo.com', 'Path' => '/',    'Discard' => true]),
            new SetCookie(['Name' => 'test', 'Value' => '123', 'Domain' => 'baz.com', 'Path' => '/foo', 'Expires' => 2]),
            new SetCookie(['Name' => 'you',  'Value' => '123', 'Domain' => 'bar.com', 'Path' => '/boo', 'Expires' => time() + 1000])
        ];
    }

    public function testCreatesFromArray()
    {
        $jar = ArrayCookieJar::fromArray([
            'foo' => 'bar',
            'baz' => 'bam'
        ], 'example.com');
        $this->assertCount(2, $jar);
    }

    /**
     * Provides test data for cookie cookieJar retrieval
     */
    public function getCookiesDataProvider()
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
            $this->assertTrue($this->jar->add($cookie));
        }

        $this->assertEquals(3, count($this->jar));
        $this->assertEquals(3, count($this->jar->getIterator()));
        $this->assertEquals($cookies, $this->jar->all(null, null, null, false, false));
    }

    public function testRemovesExpiredCookies()
    {
        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->add($cookie);
        }
        $this->jar->removeExpired();
        $this->assertEquals(array($cookies[0], $cookies[2]), $this->jar->all());
    }

    public function testRemovesTemporaryCookies()
    {
        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->add($cookie);
        }
        $this->jar->removeTemporary();
        $this->assertEquals(array($cookies[2]), $this->jar->all());
    }

    public function testIsSerializable()
    {
        $this->assertEquals('[]', $this->jar->serialize());
        $this->jar->unserialize('[]');
        $this->assertEquals(array(), $this->jar->all());

        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->add($cookie);
        }

        // Remove discard and expired cookies
        $serialized = $this->jar->serialize();
        $data = json_decode($serialized, true);
        $this->assertEquals(1, count($data));

        $a = new ArrayCookieJar();
        $a->unserialize($serialized);
        $this->assertEquals(1, count($a));
    }

    public function testRemovesSelectively()
    {
        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->add($cookie);
        }

        // Remove foo.com cookies
        $this->jar->remove('foo.com');
        $this->assertEquals(2, count($this->jar));
        // Try again, removing no further cookies
        $this->jar->remove('foo.com');
        $this->assertEquals(2, count($this->jar));

        // Remove bar.com cookies with path of /boo
        $this->jar->remove('bar.com', '/boo');
        $this->assertEquals(1, count($this->jar));

        // Remove cookie by name
        $this->jar->remove(null, null, 'test');
        $this->assertEquals(0, count($this->jar));
    }

    public function testDoesNotAddIncompleteCookies()
    {
        $this->assertEquals(false, $this->jar->add(new SetCookie()));
        $this->assertFalse($this->jar->add(new SetCookie(array(
            'Name' => 'foo'
        ))));
        $this->assertFalse($this->jar->add(new SetCookie(array(
            'Name' => false
        ))));
        $this->assertFalse($this->jar->add(new SetCookie(array(
            'Name' => true
        ))));
        $this->assertFalse($this->jar->add(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com'
        ))));
    }

    public function testDoesAddValidCookies()
    {
        $this->assertTrue($this->jar->add(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com',
            'Value'  => 0
        ))));
        $this->assertTrue($this->jar->add(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com',
            'Value'  => 0.0
        ))));
        $this->assertTrue($this->jar->add(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com',
            'Value'  => '0'
        ))));
    }

    public function testOverwritesCookiesThatAreOlderOrDiscardable()
    {
        $t = time() + 1000;
        $data = array(
            'Name'    => 'foo',
            'Value'   => 'bar',
            'Domain'  => '.example.com',
            'Path'    => '/',
            'Max-Age' => '86400',
            'Secure'  => true,
            'Discard' => true,
            'Expires' => $t
        );

        // Make sure that the discard cookie is overridden with the non-discard
        $this->assertTrue($this->jar->add(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        $data['Discard'] = false;
        $this->assertTrue($this->jar->add(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        $c = $this->jar->all();
        $this->assertEquals(false, $c[0]->getDiscard());

        // Make sure it doesn't duplicate the cookie
        $this->jar->add(new SetCookie($data));
        $this->assertEquals(1, count($this->jar));

        // Make sure the more future-ful expiration date supersede the other
        $data['Expires'] = time() + 2000;
        $this->assertTrue($this->jar->add(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));
        $c = $this->jar->all();
        $this->assertNotEquals($t, $c[0]->getExpires());
    }

    public function testOverwritesCookiesThatHaveChanged()
    {
        $t = time() + 1000;
        $data = array(
            'Name'    => 'foo',
            'Value'   => 'bar',
            'Domain'  => '.example.com',
            'Path'    => '/',
            'Max-Age' => '86400',
            'Secure'  => true,
            'Discard' => true,
            'Expires' => $t
        );

        // Make sure that the discard cookie is overridden with the non-discard
        $this->assertTrue($this->jar->add(new SetCookie($data)));

        $data['Value'] = 'boo';
        $this->assertTrue($this->jar->add(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        // Changing the value plus a parameter also must overwrite the existing one
        $data['Value'] = 'zoo';
        $data['Secure'] = false;
        $this->assertTrue($this->jar->add(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        $c = $this->jar->all();
        $this->assertEquals('zoo', $c[0]->getValue());
    }

    public function testAddsCookiesFromResponseWithRequest()
    {
        $response = new Response(200, array(
            'Set-Cookie' => "fpc=d=.Hm.yh4.1XmJWjJfs4orLQzKzPImxklQoxXSHOZATHUSEFciRueW_7704iYUtsXNEXq0M92Px2glMdWypmJ7HIQl6XIUvrZimWjQ3vIdeuRbI.FNQMAfcxu_XN1zSx7l.AcPdKL6guHc2V7hIQFhnjRW0rxm2oHY1P4bGQxFNz7f.tHm12ZD3DbdMDiDy7TBXsuP4DM-&v=2; expires=Fri, 02-Mar-2019 02:17:40 GMT;"
        ));
        $request = new Request('GET', 'http://www.example.com');
        $this->jar->addCookiesFromResponse($request, $response);
        $this->assertEquals(1, count($this->jar));
    }

    public function getMatchingCookiesDataProvider()
    {
        return array(
            array('https://example.com', array(0, 1)),
            array('http://example.com', array()),
            array('https://example.com:8912', array(0, 1)),
            array('https://foo.example.com', array(0, 1)),
            array('http://foo.example.com/test/acme/', array(4))
        );
    }

    /**
     * @dataProvider getMatchingCookiesDataProvider
     */
    public function testReturnsCookiesMatchingRequests($url, $cookies)
    {
        $bag = array(
            new SetCookie(array(
                'Name'    => 'foo',
                'Value'   => 'bar',
                'Domain'  => 'example.com',
                'Path'    => '/',
                'Max-Age' => '86400',
                'Secure'  => true
            )),
            new SetCookie(array(
                'Name'    => 'baz',
                'Value'   => 'foobar',
                'Domain'  => 'example.com',
                'Path'    => '/',
                'Max-Age' => '86400',
                'Secure'  => true
            )),
            new SetCookie(array(
                'Name'    => 'test',
                'Value'   => '123',
                'Domain'  => 'www.foobar.com',
                'Path'    => '/path/',
                'Discard' => true
            )),
            new SetCookie(array(
                'Name'    => 'muppet',
                'Value'   => 'cookie_monster',
                'Domain'  => '.y.example.com',
                'Path'    => '/acme/',
                'Expires' => time() + 86400
            )),
            new SetCookie(array(
                'Name'    => 'googoo',
                'Value'   => 'gaga',
                'Domain'  => '.example.com',
                'Path'    => '/test/acme/',
                'Max-Age' => 1500
            ))
        );

        foreach ($bag as $cookie) {
            $this->jar->add($cookie);
        }

        $request = new Request('GET', $url);
        $results = $this->jar->getMatchingCookies($request);
        $this->assertEquals(count($cookies), count($results), var_export($results, true));
        foreach ($cookies as $i) {
            $this->assertContains($bag[$i], $results);
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Invalid cookie: Cookie name must not cannot invalid characters:
     */
    public function testThrowsExceptionWithStrictMode()
    {
        $a = new ArrayCookieJar();
        $a->setStrictMode(true);
        $a->add(new SetCookie(['Name' => "abc\n", 'Value' => 'foo', 'Domain' => 'bar']));
    }
}
