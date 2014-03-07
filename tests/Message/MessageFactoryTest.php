<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Client;
use GuzzleHttp\Post\PostFile;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Subscriber\Cookie;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Query;

/**
 * @covers GuzzleHttp\Message\MessageFactory
 */
class MessageFactoryTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesResponses()
    {
        $f = new MessageFactory();
        $response = $f->createResponse(200, ['foo' => 'bar'], 'test', [
            'protocol_version' => 1.0
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['foo' => ['bar']], $response->getHeaders());
        $this->assertEquals('test', $response->getBody());
        $this->assertEquals(1.0, $response->getProtocolVersion());
    }

    public function testCreatesRequestFromMessage()
    {
        $f = new MessageFactory();
        $req = $f->fromMessage("GET / HTTP/1.1\r\nBaz: foo\r\n\r\n");
        $this->assertEquals('GET', $req->getMethod());
        $this->assertEquals('/', $req->getPath());
        $this->assertEquals('foo', $req->getHeader('Baz'));
        $this->assertNull($req->getBody());
    }

    public function testCreatesRequestFromMessageWithBody()
    {
        $req = (new MessageFactory())->fromMessage("GET / HTTP/1.1\r\nBaz: foo\r\n\r\ntest");
        $this->assertEquals('test', $req->getBody());
    }

    public function testCreatesRequestWithPostBody()
    {
        $req = (new MessageFactory())->createRequest('GET', 'http://www.foo.com', ['body' => ['abc' => '123']]);
        $this->assertEquals('abc=123', $req->getBody());
    }

    public function testCreatesRequestWithPostBodyAndPostFiles()
    {
        $pf = fopen(__FILE__, 'r');
        $pfi = new PostFile('ghi', 'abc', __FILE__);
        $req = (new MessageFactory())->createRequest('GET', 'http://www.foo.com', [
            'body' => [
                'abc' => '123',
                'def' => $pf,
                'ghi' => $pfi
            ]
        ]);
        $this->assertInstanceOf('GuzzleHttp\Post\PostBody', $req->getBody());
        $s = (string) $req;
        $this->assertContains('testCreatesRequestWithPostBodyAndPostFiles', $s);
        $this->assertContains('multipart/form-data', $s);
        $this->assertTrue(in_array($pfi, $req->getBody()->getFiles(), true));
    }

    public function testCreatesResponseFromMessage()
    {
        $response = (new MessageFactory)->fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('4', $response->getHeader('Content-Length'));
        $this->assertEquals('test', $response->getBody(true));
    }

    public function testCanCreateHeadResponses()
    {
        $response = (new MessageFactory)->fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\n");
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(null, $response->getBody());
        $this->assertEquals('4', $response->getHeader('Content-Length'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testFactoryRequiresMessageForRequest()
    {
        (new MessageFactory)->fromMessage('');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage foo
     */
    public function testValidatesOptionsAreImplemented()
    {
        (new MessageFactory)->createRequest('GET', 'http://test.com', ['foo' => 'bar']);
    }

    public function testOptionsAddsRequestOptions()
    {
        $request = (new MessageFactory)->createRequest(
            'GET', 'http://test.com', ['config' => ['baz' => 'bar']]
        );
        $this->assertEquals('bar', $request->getConfig()->get('baz'));
    }

    public function testCanDisableRedirects()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', ['allow_redirects' => false]);
        $this->assertEmpty($request->getEmitter()->listeners('complete'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesRedirects()
    {
        (new MessageFactory)->createRequest('GET', '/', ['allow_redirects' => []]);
    }

    public function testCanEnableStrictRedirectsAndSpecifyMax()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', [
            'allow_redirects' => ['max' => 10, 'strict' => true]
        ]);
        $this->assertTrue($request->getConfig()['redirect']['strict']);
        $this->assertEquals(10, $request->getConfig()['redirect']['max']);
    }

    public function testCanAddCookiesFromHash()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://www.test.com/', [
            'cookies' => ['Foo' => 'Bar']
        ]);
        $cookies = null;
        foreach ($request->getEmitter()->listeners('before') as $l) {
            if ($l[0] instanceof Cookie) {
                $cookies = $l[0];
                break;
            }
        }
        if (!$cookies) {
            $this->fail('Did not add cookie listener');
        } else {
            $this->assertCount(1, $cookies->getCookieJar());
        }
    }

    public function testAddsCookieUsingTrue()
    {
        $factory = new MessageFactory();
        $request1 = $factory->createRequest('GET', '/', ['cookies' => true]);
        $request2 = $factory->createRequest('GET', '/', ['cookies' => true]);
        $listeners = function ($r) {
            return array_filter($r->getEmitter()->listeners('before'), function ($l) {
                return $l[0] instanceof Cookie;
            });
        };
        $this->assertSame($listeners($request1), $listeners($request2));
    }

    public function testAddsCookieFromCookieJar()
    {
        $jar = new CookieJar();
        $request = (new MessageFactory)->createRequest('GET', '/', ['cookies' => $jar]);
        foreach ($request->getEmitter()->listeners('before') as $l) {
            if ($l[0] instanceof Cookie) {
                $this->assertSame($jar, $l[0]->getCookieJar());
            }
        }
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesCookies()
    {
        (new MessageFactory)->createRequest('GET', '/', ['cookies' => 'baz']);
    }

    public function testCanAddQuery()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [
            'query' => ['Foo' => 'Bar']
        ]);
        $this->assertEquals('Bar', $request->getQuery()->get('Foo'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesQuery()
    {
        (new MessageFactory)->createRequest('GET', 'http://foo.com', [
            'query' => 'foo'
        ]);
    }

    public function testCanSetDefaultQuery()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com?test=abc', [
            'query' => ['Foo' => 'Bar', 'test' => 'def']
        ]);
        $this->assertEquals('Bar', $request->getQuery()->get('Foo'));
        $this->assertEquals('abc', $request->getQuery()->get('test'));
    }

    public function testCanSetDefaultQueryWithObject()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com?test=abc', [
            'query' => new Query(['Foo' => 'Bar', 'test' => 'def'])
        ]);
        $this->assertEquals('Bar', $request->getQuery()->get('Foo'));
        $this->assertEquals('abc', $request->getQuery()->get('test'));
    }

    public function testCanAddBasicAuth()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [
            'auth' => ['michael', 'test']
        ]);
        $this->assertTrue($request->hasHeader('Authorization'));
    }

    public function testCanAddDigestAuth()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [
            'auth' => ['michael', 'test', 'digest']
        ]);
        $this->assertEquals('michael:test', $request->getConfig()->getPath('curl/' . CURLOPT_USERPWD));
        $this->assertEquals(CURLAUTH_DIGEST, $request->getConfig()->getPath('curl/' . CURLOPT_HTTPAUTH));
    }

    public function testCanDisableAuth()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [
            'auth' => false
        ]);
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function testCanSetCustomAuth()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [
            'auth' => 'foo'
        ]);
        $this->assertEquals('foo', $request->getConfig()['auth']);
    }

    public function testCanAddEvents()
    {
        $foo = null;
        $client = new Client();
        $client->getEmitter()->attach(new Mock([new Response(200)]));
        $client->get('http://test.com', [
            'events' => [
                'before' => function () use (&$foo) { $foo = true; }
            ]
        ]);
        $this->assertTrue($foo);
    }

    public function testCanAddEventsWithPriority()
    {
        $foo = null;
        $client = new Client();
        $client->getEmitter()->attach(new Mock(array(new Response(200))));
        $request = $client->createRequest('GET', 'http://test.com', [
            'events' => [
                'before' => [
                    'fn' => function () use (&$foo) { $foo = true; },
                    'priority' => 123
                ]
            ]
        ]);
        $client->send($request);
        $this->assertTrue($foo);
        $l = $this->readAttribute($request->getEmitter(), 'listeners');
        $this->assertArrayHasKey(123, $l['before']);
    }

    public function testCanAddEventsOnce()
    {
        $foo = 0;
        $client = new Client();
        $client->getEmitter()->attach(new Mock([
            new Response(200),
            new Response(200),
        ]));
        $fn = function () use (&$foo) { ++$foo; };
        $request = $client->createRequest('GET', 'http://test.com', [
            'events' => ['before' => ['fn' => $fn, 'once' => true]]
        ]);
        $client->send($request);
        $this->assertEquals(1, $foo);
        $client->send($request);
        $this->assertEquals(1, $foo);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEventContainsFn()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $client->createRequest('GET', '/', ['events' => ['before' => ['foo' => 'bar']]]);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEventIsArray()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $client->createRequest('GET', '/', ['events' => ['before' => '123']]);
    }

    public function testCanAddSubscribers()
    {
        $mock = new Mock([new Response(200)]);
        $client = new Client();
        $client->getEmitter()->attach($mock);
        $request = $client->get('http://test.com', ['subscribers' => [$mock]]);
    }

    public function testCanDisableExceptions()
    {
        $client = new Client();
        $this->assertEquals(500, $client->get('http://test.com', [
            'subscribers' => [new Mock([new Response(500)])],
            'exceptions' => false
        ])->getStatusCode());
    }

    public function testCanChangeSaveToLocation()
    {
        $saveTo = Stream::factory();
        $request = (new MessageFactory)->createRequest('GET', '/', ['save_to' => $saveTo]);
        $this->assertSame($saveTo, $request->getConfig()->get('save_to'));
    }

    public function testCanSetProxy()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', ['proxy' => '192.168.16.121']);
        $this->assertEquals('192.168.16.121', $request->getConfig()->get('proxy'));
    }

    public function testCanSetHeadersOption()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', ['headers' => ['Foo' => 'Bar']]);
        $this->assertEquals('Bar', (string) $request->getHeader('Foo'));
    }

    public function testCanSetHeaders()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [
            'headers' => ['Foo' => ['Baz', 'Bar'], 'Test' => '123']
        ]);
        $this->assertEquals('Baz, Bar', $request->getHeader('Foo'));
        $this->assertEquals('123', $request->getHeader('Test'));
    }

    public function testCanSetTimeoutOption()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['timeout' => 1.5]);
        $this->assertEquals(1.5, $request->getConfig()->get('timeout'));
    }

    public function testCanSetConnectTimeoutOption()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['connect_timeout' => 1.5]);
        $this->assertEquals(1.5, $request->getConfig()->get('connect_timeout'));
    }

    public function testCanSetDebug()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['debug' => true]);
        $this->assertTrue($request->getConfig()->get('debug'));
    }

    public function testCanSetVerifyToOff()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['verify' => false]);
        $this->assertFalse($request->getConfig()->get('verify'));
    }

    public function testCanSetVerifyToOn()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['verify' => true]);
        $this->assertTrue($request->getConfig()->get('verify'));
    }

    public function testCanSetVerifyToPath()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['verify' => '/foo.pem']);
        $this->assertEquals('/foo.pem', $request->getConfig()->get('verify'));
    }

    public function inputValidation()
    {
        return array_map(function ($option) { return array($option); }, array(
            'headers', 'events', 'subscribers', 'params'
        ));
    }

    /**
     * @dataProvider inputValidation
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesInput($option)
    {
        (new MessageFactory())->createRequest('GET', '/', [$option => 'foo']);
    }

    public function testCanAddSslKey()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['ssl_key' => '/foo.pem']);
        $this->assertEquals('/foo.pem', $request->getConfig()->get('ssl_key'));
    }

    public function testCanAddSslKeyPassword()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['ssl_key' => ['/foo.pem', 'bar']]);
        $this->assertEquals(['/foo.pem', 'bar'], $request->getConfig()->get('ssl_key'));
    }

    public function testCanAddSslCert()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['cert' => '/foo.pem']);
        $this->assertEquals('/foo.pem', $request->getConfig()->get('cert'));
    }

    public function testCanAddSslCertPassword()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', ['cert' => ['/foo.pem', 'bar']]);
        $this->assertEquals(['/foo.pem', 'bar'], $request->getConfig()->get('cert'));
    }

    public function testCreatesBodyWithoutZeroString()
    {
        $request = (new MessageFactory())->createRequest('PUT', 'http://test.com', ['body' => '0']);
        $this->assertSame('0', (string) $request->getBody());
    }

    public function testCanSetProtocolVersion()
    {
        $request = (new MessageFactory())->createRequest('GET', 'http://test.com', ['version' => 1.0]);
        $this->assertEquals(1.0, $request->getProtocolVersion());
    }
}
