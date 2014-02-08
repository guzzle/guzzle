<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Client;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Subscriber\Cookie;
use Guzzle\Http\Subscriber\CookieJar\ArrayCookieJar;
use Guzzle\Http\Subscriber\Redirect;
use Guzzle\Http\Subscriber\Mock;
use Guzzle\Stream\Stream;

/**
 * @covers Guzzle\Http\Message\MessageFactory
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
        $this->assertEquals([
            'Content-Length' => '4',
            'foo' => 'bar'
        ], $response->getHeaders());
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
        $req = (new MessageFactory())->createRequest('GET', 'http://www.foo.com', [], ['abc' => '123']);
        $this->assertEquals('abc=123', $req->getBody());
    }

    public function testCreatesRequestWithPostBodyAndPostFiles()
    {
        $pf = fopen(__FILE__, 'r');
        $req = (new MessageFactory())->createRequest('GET', 'http://www.foo.com', [], [
            'abc' => '123',
            'def' => $pf
        ]);
        $this->assertInstanceOf('Guzzle\Http\Message\Post\PostBody', $req->getBody());
        $s = (string) $req;
        $this->assertContains('testCreatesRequestWithPostBodyAndPostFiles', $s);
        $this->assertContains('multipart/form-data', $s);
    }

    public function testCreatesResponseFromMessage()
    {
        $response = (new MessageFactory)->fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('4', $response->getHeader('Content-Length'));
        $this->assertEquals('test', $response->getBody(true));

        // Make sure that automatic Content-Length works
        $response = (new MessageFactory)->fromMessage("HTTP/1.1 200 OK\r\n\r\ntest");
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
        (new MessageFactory)->createRequest('GET', 'http://test.com', [], null, ['foo' => 'bar']);
    }

    public function testOptionsAddsRequestOptions()
    {
        $request = (new MessageFactory)->createRequest(
            'GET', 'http://test.com', [], null, ['config' => ['baz' => 'bar']]
        );
        $this->assertEquals('bar', $request->getConfig()->get('baz'));
    }

    public function testCanDisableRedirects()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['allow_redirects' => false]);
        $this->assertEmpty($request->getEmitter()->getListeners(RequestEvents::AFTER_SEND));
    }

    public function testCanEnableStrictRedirects()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['allow_redirects' => 'strict']);
        $this->assertTrue($request->getConfig()->get(Redirect::STRICT_REDIRECTS));
    }

    public function testCanEnableStrictRedirectsWithInt()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['allow_redirects' => 10]);
        $this->assertEquals(10, $request->getConfig()->get(Redirect::MAX_REDIRECTS));
    }

    public function testCanAddCookiesFromHash()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://www.test.com/', [], null, ['cookies' => ['Foo' => 'Bar']]);
        $cookies = null;
        foreach ($request->getEmitter()->getListeners(RequestEvents::BEFORE_SEND) as $l) {
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
        $request1 = $factory->createRequest('GET', '/', [], null, ['cookies' => true]);
        $request2 = $factory->createRequest('GET', '/', [], null, ['cookies' => true]);
        $listeners = function ($r) {
            return array_filter($r->getEmitter()->getListeners(RequestEvents::BEFORE_SEND), function ($l) {
                return $l[0] instanceof Cookie;
            });
        };
        $this->assertSame($listeners($request1), $listeners($request2));
    }

    public function testAddsCookieFromCookieJar()
    {
        $jar = new ArrayCookieJar();
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['cookies' => $jar]);
        foreach ($request->getEmitter()->getListeners(RequestEvents::BEFORE_SEND) as $l) {
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
        (new MessageFactory)->createRequest('GET', '/', [], null, ['cookies' => 'baz']);
    }

    public function testCanAddQueryString()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [], null, [
            'query' => ['Foo' => 'Bar']
        ]);
        $this->assertEquals('Bar', $request->getQuery()->get('Foo'));
    }

    public function testCanSetDefaultQueryString()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com?test=abc', [], null, [
            'query' => ['Foo' => 'Bar', 'test' => 'def']
        ]);
        $this->assertEquals('Bar', $request->getQuery()->get('Foo'));
        $this->assertEquals('abc', $request->getQuery()->get('test'));
    }

    public function testCanAddBasicAuth()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [], null, [
            'auth' => ['michael', 'test']
        ]);
        $this->assertTrue($request->hasHeader('Authorization'));
    }

    public function testCanAddDigestAuth()
    {
        $request = (new MessageFactory)->createRequest('GET', 'http://foo.com', [], null, [
            'auth' => ['michael', 'test', 'digest']
        ]);
        $this->assertEquals(['michael', 'test', 'digest'], $request->getConfig()->get('auth'));
    }

    public function testCanAddEvents()
    {
        $foo = null;
        $client = new Client();
        $client->getEmitter()->addSubscriber(new Mock([new Response(200)]));
        $request = $client->get('/', [], [
            'events' => [
                RequestEvents::BEFORE_SEND => function () use (&$foo) { $foo = true; }
            ]
        ]);
        $this->assertTrue($foo);
    }

    public function testCanAddEventsWithPriority()
    {
        $foo = null;
        $client = new Client();
        $client->getEmitter()->addSubscriber(new Mock(array(new Response(200))));
        $request = $client->get('/', [], [
            'events' => [
                RequestEvents::BEFORE_SEND => array(function () use (&$foo) { $foo = true; }, 100)
            ]
        ]);
        $this->assertTrue($foo);
    }

    public function testCanAddSubscribers()
    {
        $mock = new Mock([new Response(200)]);
        $client = new Client();
        $client->getEmitter()->addSubscriber($mock);
        $request = $client->get('/', [], ['subscribers' => [$mock]]);
    }

    public function testCanDisableExceptions()
    {
        $client = new Client();
        $this->assertEquals(500, $client->get('/', [], [
            'subscribers' => [new Mock([new Response(500)])],
            'exceptions' => false
        ])->getStatusCode());
    }

    public function testCanChangeSaveToLocation()
    {
        $saveTo = Stream::factory();
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['save_to' => $saveTo]);
        $this->assertSame($saveTo, $request->getConfig()->get('save_to'));
    }

    public function testCanSetProxy()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['proxy' => '192.168.16.121']);
        $this->assertEquals('192.168.16.121', $request->getConfig()->get('proxy'));
    }

    public function testCanSetHeadersOption()
    {
        $request = (new MessageFactory)->createRequest('GET', '/', [], null, ['headers' => ['Foo' => 'Bar']]);
        $this->assertEquals('Bar', (string) $request->getHeader('Foo'));
    }

    public function testCanSetDefaultHeadersOptions()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [
            'Foo' => 'baz'
        ], null, ['headers' => ['Foo' => 'Bar', 'Test' => '123']]);
        $this->assertEquals('baz', $request->getHeader('Foo'));
        $this->assertEquals('123', $request->getHeader('Test'));
    }

    public function testCanSetTimeoutOption()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['timeout' => 1.5]);
        $this->assertEquals(1.5, $request->getConfig()->get('timeout'));
    }

    public function testCanSetConnectTimeoutOption()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['connect_timeout' => 1.5]);
        $this->assertEquals(1.5, $request->getConfig()->get('connect_timeout'));
    }

    public function testCanSetDebug()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['debug' => true]);
        $this->assertTrue($request->getConfig()->get('debug'));
    }

    public function testCanSetVerifyToOff()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['verify' => false]);
        $this->assertFalse($request->getConfig()->get('verify'));
    }

    public function testCanSetVerifyToOn()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['verify' => true]);
        $this->assertTrue($request->getConfig()->get('verify'));
    }

    public function testCanSetVerifyToPath()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['verify' => '/foo.pem']);
        $this->assertEquals('/foo.pem', $request->getConfig()->get('verify'));
    }

    public function inputValidation()
    {
        return array_map(function ($option) { return array($option); }, array(
            'headers', 'query', 'auth', 'events', 'subscribers', 'params', 'config'
        ));
    }

    /**
     * @dataProvider inputValidation
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesInput($option)
    {
        (new MessageFactory())->createRequest('GET', '/', [], null, [$option => 'foo']);
    }

    public function testCanAddSslKey()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['ssl_key' => '/foo.pem']);
        $this->assertEquals('/foo.pem', $request->getConfig()->get('ssl_key'));
    }

    public function testCanAddSslKeyPassword()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['ssl_key' => ['/foo.pem', 'bar']]);
        $this->assertEquals(['/foo.pem', 'bar'], $request->getConfig()->get('ssl_key'));
    }

    public function testCanAddSslCert()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['cert' => '/foo.pem']);
        $this->assertEquals('/foo.pem', $request->getConfig()->get('cert'));
    }

    public function testCanAddSslCertPassword()
    {
        $request = (new MessageFactory())->createRequest('GET', '/', [], null, ['cert' => ['/foo.pem', 'bar']]);
        $this->assertEquals(['/foo.pem', 'bar'], $request->getConfig()->get('cert'));
    }

    public function testCreatesBodyWithoutZeroString()
    {
        $request = (new MessageFactory())->createRequest('PUT', 'http://test.com', array(), '0');
        $this->assertSame('0', (string) $request->getBody());
    }
}
