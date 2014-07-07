<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Query;
use GuzzleHttp\Url;

/**
 * @covers GuzzleHttp\Url
 */
class UrlTest extends \PHPUnit_Framework_TestCase
{
    const RFC3986_BASE = "http://a/b/c/d;p?q";

    public function testEmptyUrl()
    {
        $url = Url::fromString('');
        $this->assertEquals('', (string) $url);
    }

    public function testPortIsDeterminedFromScheme()
    {
        $this->assertEquals(80, Url::fromString('http://www.test.com/')->getPort());
        $this->assertEquals(443, Url::fromString('https://www.test.com/')->getPort());
        $this->assertEquals(21, Url::fromString('ftp://www.test.com/')->getPort());
        $this->assertEquals(8192, Url::fromString('http://www.test.com:8192/')->getPort());
        $this->assertEquals(null, Url::fromString('foo://www.test.com/')->getPort());
    }

    public function testRemovesDefaultPortWhenSettingScheme()
    {
        $url = Url::fromString('http://www.test.com/');
        $url->setPort(80);
        $url->setScheme('https');
        $this->assertEquals(443, $url->getPort());
    }

    public function testCloneCreatesNewInternalObjects()
    {
        $u1 = Url::fromString('http://www.test.com/');
        $u2 = clone $u1;
        $this->assertNotSame($u1->getQuery(), $u2->getQuery());
    }

    public function testValidatesUrlPartsInFactory()
    {
        $url = Url::fromString('/index.php');
        $this->assertEquals('/index.php', (string) $url);
        $this->assertFalse($url->isAbsolute());

        $url = 'http://michael:test@test.com:80/path/123?q=abc#test';
        $u = Url::fromString($url);
        $this->assertEquals('http://michael:test@test.com/path/123?q=abc#test', (string) $u);
        $this->assertTrue($u->isAbsolute());
    }

    public function testAllowsFalsyUrlParts()
    {
        $url = Url::fromString('http://a:50/0?0#0');
        $this->assertSame('a', $url->getHost());
        $this->assertEquals(50, $url->getPort());
        $this->assertSame('/0', $url->getPath());
        $this->assertEquals('0', (string) $url->getQuery());
        $this->assertSame('0', $url->getFragment());
        $this->assertEquals('http://a:50/0?0#0', (string) $url);

        $url = Url::fromString('');
        $this->assertSame('', (string) $url);

        $url = Url::fromString('0');
        $this->assertSame('0', (string) $url);
    }

    public function testBuildsRelativeUrlsWithFalsyParts()
    {
        $url = Url::buildUrl(['path' => '/0']);
        $this->assertSame('/0', $url);

        $url = Url::buildUrl(['path' => '0']);
        $this->assertSame('0', $url);

        $url = Url::buildUrl(['host' => '', 'path' => '0']);
        $this->assertSame('0', $url);
    }

    public function testUrlStoresParts()
    {
        $url = Url::fromString('http://test:pass@www.test.com:8081/path/path2/?a=1&b=2#fragment');
        $this->assertEquals('http', $url->getScheme());
        $this->assertEquals('test', $url->getUsername());
        $this->assertEquals('pass', $url->getPassword());
        $this->assertEquals('www.test.com', $url->getHost());
        $this->assertEquals(8081, $url->getPort());
        $this->assertEquals('/path/path2/', $url->getPath());
        $this->assertEquals('fragment', $url->getFragment());
        $this->assertEquals('a=1&b=2', (string) $url->getQuery());

        $this->assertEquals(array(
            'fragment' => 'fragment',
            'host' => 'www.test.com',
            'pass' => 'pass',
            'path' => '/path/path2/',
            'port' => 8081,
            'query' => 'a=1&b=2',
            'scheme' => 'http',
            'user' => 'test'
        ), $url->getParts());
    }

    public function testHandlesPathsCorrectly()
    {
        $url = Url::fromString('http://www.test.com');
        $this->assertEquals('', $url->getPath());
        $url->setPath('test');
        $this->assertEquals('test', $url->getPath());

        $url->setPath('/test/123/abc');
        $this->assertEquals(array('', 'test', '123', 'abc'), $url->getPathSegments());

        $parts = parse_url('http://www.test.com/test');
        $parts['path'] = '';
        $this->assertEquals('http://www.test.com', Url::buildUrl($parts));
        $parts['path'] = 'test';
        $this->assertEquals('http://www.test.com/test', Url::buildUrl($parts));
    }

    public function testAddsQueryIfPresent()
    {
        $this->assertEquals('?foo=bar', Url::buildUrl(array(
            'query' => 'foo=bar'
        )));
    }

    public function testAddsToPath()
    {
        // Does nothing here
        $this->assertEquals('http://e.com/base?a=1', (string) Url::fromString('http://e.com/base?a=1')->addPath(false));
        $this->assertEquals('http://e.com/base?a=1', (string) Url::fromString('http://e.com/base?a=1')->addPath(''));
        $this->assertEquals('http://e.com/base?a=1', (string) Url::fromString('http://e.com/base?a=1')->addPath('/'));
        $this->assertEquals('http://e.com/base/0', (string) Url::fromString('http://e.com/base')->addPath('0'));

        $this->assertEquals('http://e.com/base/relative?a=1', (string) Url::fromString('http://e.com/base?a=1')->addPath('relative'));
        $this->assertEquals('http://e.com/base/relative?a=1', (string) Url::fromString('http://e.com/base?a=1')->addPath('/relative'));
    }

    /**
     * URL combination data provider
     *
     * @return array
     */
    public function urlCombineDataProvider()
    {
        return [
            // Specific test cases
            ['http://www.example.com/',           'http://www.example.com/', 'http://www.example.com/'],
            ['http://www.example.com/path',       '/absolute', 'http://www.example.com/absolute'],
            ['http://www.example.com/path',       '/absolute?q=2', 'http://www.example.com/absolute?q=2'],
            ['http://www.example.com/',           '?q=1', 'http://www.example.com/?q=1'],
            ['http://www.example.com/path',       'http://test.com', 'http://test.com'],
            ['http://www.example.com:8080/path',  'http://test.com', 'http://test.com'],
            ['http://www.example.com:8080/path',  '?q=2#abc', 'http://www.example.com:8080/path?q=2#abc'],
            ['http://www.example.com/path',       'http://u:a@www.example.com/', 'http://u:a@www.example.com/'],
            ['/path?q=2', 'http://www.test.com/', 'http://www.test.com/path?q=2'],
            ['http://api.flickr.com/services/',   'http://www.flickr.com/services/oauth/access_token', 'http://www.flickr.com/services/oauth/access_token'],
            ['https://www.example.com/path',       '//foo.com/abc', 'https://foo.com/abc'],
            // RFC 3986 test cases
            [self::RFC3986_BASE, 'g:h',           'g:h'],
            [self::RFC3986_BASE, 'g',             'http://a/b/c/g'],
            [self::RFC3986_BASE, './g',           'http://a/b/c/g'],
            [self::RFC3986_BASE, 'g/',            'http://a/b/c/g/'],
            [self::RFC3986_BASE, '/g',            'http://a/g'],
            [self::RFC3986_BASE, '//g',           'http://g'],
            [self::RFC3986_BASE, '?y',            'http://a/b/c/d;p?y'],
            [self::RFC3986_BASE, 'g?y',           'http://a/b/c/g?y'],
            [self::RFC3986_BASE, '#s',            'http://a/b/c/d;p?q#s'],
            [self::RFC3986_BASE, 'g#s',           'http://a/b/c/g#s'],
            [self::RFC3986_BASE, 'g?y#s',         'http://a/b/c/g?y#s'],
            [self::RFC3986_BASE, ';x',            'http://a/b/c/;x'],
            [self::RFC3986_BASE, 'g;x',           'http://a/b/c/g;x'],
            [self::RFC3986_BASE, 'g;x?y#s',       'http://a/b/c/g;x?y#s'],
            [self::RFC3986_BASE, '',              self::RFC3986_BASE],
            [self::RFC3986_BASE, '.',             'http://a/b/c/'],
            [self::RFC3986_BASE, './',            'http://a/b/c/'],
            [self::RFC3986_BASE, '..',            'http://a/b/'],
            [self::RFC3986_BASE, '../',           'http://a/b/'],
            [self::RFC3986_BASE, '../g',          'http://a/b/g'],
            [self::RFC3986_BASE, '../..',         'http://a/'],
            [self::RFC3986_BASE, '../../',        'http://a/'],
            [self::RFC3986_BASE, '../../g',       'http://a/g'],
            [self::RFC3986_BASE, '../../../g',    'http://a/g'],
            [self::RFC3986_BASE, '../../../../g', 'http://a/g'],
            [self::RFC3986_BASE, '/./g',          'http://a/g'],
            [self::RFC3986_BASE, '/../g',         'http://a/g'],
            [self::RFC3986_BASE, 'g.',            'http://a/b/c/g.'],
            [self::RFC3986_BASE, '.g',            'http://a/b/c/.g'],
            [self::RFC3986_BASE, 'g..',           'http://a/b/c/g..'],
            [self::RFC3986_BASE, '..g',           'http://a/b/c/..g'],
            [self::RFC3986_BASE, './../g',        'http://a/b/g'],
            [self::RFC3986_BASE, 'foo////g',      'http://a/b/c/foo////g'],
            [self::RFC3986_BASE, './g/.',         'http://a/b/c/g/'],
            [self::RFC3986_BASE, 'g/./h',         'http://a/b/c/g/h'],
            [self::RFC3986_BASE, 'g/../h',        'http://a/b/c/h'],
            [self::RFC3986_BASE, 'g;x=1/./y',     'http://a/b/c/g;x=1/y'],
            [self::RFC3986_BASE, 'g;x=1/../y',    'http://a/b/c/y'],
            [self::RFC3986_BASE, 'http:g',        'http:g'],
        ];
    }

    /**
     * @dataProvider urlCombineDataProvider
     */
    public function testCombinesUrls($a, $b, $c)
    {
        $this->assertEquals($c, (string) Url::fromString($a)->combine($b));
    }

    public function testHasGettersAndSetters()
    {
        $url = Url::fromString('http://www.test.com/');
        $this->assertEquals('example.com', $url->setHost('example.com')->getHost());
        $this->assertEquals('8080', $url->setPort(8080)->getPort());
        $this->assertEquals('/foo/bar', $url->setPath('/foo/bar')->getPath());
        $this->assertEquals('a', $url->setPassword('a')->getPassword());
        $this->assertEquals('b', $url->setUsername('b')->getUsername());
        $this->assertEquals('abc', $url->setFragment('abc')->getFragment());
        $this->assertEquals('https', $url->setScheme('https')->getScheme());
        $this->assertEquals('a=123', (string) $url->setQuery('a=123')->getQuery());
        $this->assertEquals('https://b:a@example.com:8080/foo/bar?a=123#abc', (string) $url);
        $this->assertEquals('b=boo', (string) $url->setQuery(new Query(array(
            'b' => 'boo'
        )))->getQuery());
        $this->assertEquals('https://b:a@example.com:8080/foo/bar?b=boo#abc', (string) $url);
    }

    public function testSetQueryAcceptsArray()
    {
        $url = Url::fromString('http://www.test.com');
        $url->setQuery(array('a' => 'b'));
        $this->assertEquals('http://www.test.com?a=b', (string) $url);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testQueryMustBeValid()
    {
        $url = Url::fromString('http://www.test.com');
        $url->setQuery(false);
    }

    public function urlProvider()
    {
        return array(
            array('/foo/..', '/'),
            array('//foo//..', '//foo/'),
            array('/foo//', '/foo//'),
            array('/foo/../..', '/'),
            array('/foo/../.', '/'),
            array('/./foo/..', '/'),
            array('/./foo', '/foo'),
            array('/./foo/', '/foo/'),
            array('*', '*'),
            array('/foo', '/foo'),
            array('/abc/123/../foo/', '/abc/foo/'),
            array('/a/b/c/./../../g', '/a/g'),
            array('/b/c/./../../g', '/g'),
            array('/b/c/./../../g', '/g'),
            array('/c/./../../g', '/g'),
            array('/./../../g', '/g'),
            array('foo', 'foo'),
        );
    }

    /**
     * @dataProvider urlProvider
     */
    public function testRemoveDotSegments($path, $result)
    {
        $url = Url::fromString('http://www.example.com');
        $url->setPath($path)->removeDotSegments();
        $this->assertEquals($result, $url->getPath());
    }

    public function testSettingHostWithPortModifiesPort()
    {
        $url = Url::fromString('http://www.example.com');
        $url->setHost('foo:8983');
        $this->assertEquals('foo', $url->getHost());
        $this->assertEquals(8983, $url->getPort());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesUrlCanBeParsed()
    {
        Url::fromString('foo:////');
    }

    public function testConvertsSpecialCharsInPathWhenCastingToString()
    {
        $url = Url::fromString('http://foo.com/baz bar?a=b');
        $url->addPath('?');
        $this->assertEquals('http://foo.com/baz%20bar/%3F?a=b', (string) $url);
    }
}
