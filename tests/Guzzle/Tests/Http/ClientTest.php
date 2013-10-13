<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\Adapter\MockAdapter;
use Guzzle\Http\Client;
use Guzzle\Http\Event\ClientEvents;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Exception\RequestException;

/**
 * @covers Guzzle\Http\Client
 */
class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testProvidesDefaultUserAgent()
    {
        $this->assertEquals(1, preg_match('#^Guzzle/.+ curl/.+ PHP/.+$#', Client::getDefaultUserAgent()));
    }

    public function testUsesDefaultDefaultOptions()
    {
        $client = new Client();
        $this->assertTrue($client->getDefaultOption('allow_redirects'));
        $this->assertTrue($client->getDefaultOption('exceptions'));
        $this->assertContains('cacert.pem', $client->getDefaultOption('verify'));
    }

    public function testUsesProvidedDefaultOptions()
    {
        $client = new Client([
            'defaults' => [
                'allow_redirects' => false,
                'query' => ['foo' => 'bar']
            ]
        ]);
        $this->assertFalse($client->getDefaultOption('allow_redirects'));
        $this->assertTrue($client->getDefaultOption('exceptions'));
        $this->assertContains('cacert.pem', $client->getDefaultOption('verify'));
        $this->assertEquals(['foo' => 'bar'], $client->getDefaultOption('query'));
    }

    public function testCanSpecifyBaseUrl()
    {
        $this->assertEquals(null, (new Client())->getBaseUrl());
        $this->assertEquals('http://foo', (new Client([
            'base_url' => 'http://foo'
        ]))->getBaseUrl());
    }

    public function testCanSpecifyBaseUrlUriTemplate()
    {
        $this->assertEquals('http://foo.com/baz/', (new Client([
            'base_url' => ['http://foo.com/{var}/', ['var' => 'baz']]
        ]))->getBaseUrl());
    }

    public function testClientUsesDefaultAdapterWhenNoneIsSet()
    {
        $client = new Client();
        $response = $client->get('', [], ['future' => true]);
        $adapter = extension_loaded('curl')
            ? 'Guzzle\Http\Adapter\Curl\CurlAdapter'
            : 'Guzzle\Http\Adapter\StreamAdapter';
        $this->assertInstanceOf($adapter, $response->getAdapter());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyAdapter()
    {
        $adapter = $this->getMockBuilder('Guzzle\Http\Adapter\AdapterInterface')
            ->setMethods('send')
            ->getMockForAbstractClass();
        $adapter->expects($this->once())
            ->method('send')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['adapter' => $adapter]);
        $client->get();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyMessageFactory()
    {
        $factory = $this->getMockBuilder('Guzzle\Http\Message\MessageFactoryInterface')
            ->setMethods('createRequest')
            ->getMockForAbstractClass();
        $factory->expects($this->once())
            ->method('createRequest')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['message_factory' => $factory]);
        $client->get();
    }

    public function testAddsDefaultUserAgentHeaderWithDefaultOptions()
    {
        $client = new Client(['defaults' => ['allow_redirects' => false]]);
        $this->assertFalse($client->getDefaultOption('allow_redirects'));
        $this->assertEquals(['User-Agent' => Client::getDefaultUserAgent()], $client->getDefaultOption('headers'));
    }

    public function testAddsDefaultUserAgentHeaderWithoutDefaultOptions()
    {
        $client = new Client();
        $this->assertEquals(['User-Agent' => Client::getDefaultUserAgent()], $client->getDefaultOption('headers'));
    }

    public function testProvidesConfigPathValues()
    {
        $client = new Client(['foo' => ['baz' => 'bar']]);
        $this->assertEquals('bar', $client->getConfig('foo/baz'));
    }

    private function getRequestClient()
    {
        $client = $this->getMockBuilder('Guzzle\Http\Client')
            ->setMethods(['send'])
            ->getMock();
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnArgument(0));

        return $client;
    }

    public function requestMethodProvider()
    {
        return [
            ['GET', false],
            ['HEAD', false],
            ['DELETE', false],
            ['OPTIONS', false],
            ['POST', 'foo'],
            ['PUT', 'foo'],
            ['PATCH', 'foo']
        ];
    }

    /**
     * @dataProvider requestMethodProvider
     */
    public function testClientProvidesMethodShortcut($method, $body)
    {
        $client = $this->getRequestClient();
        if ($body) {
            $request = $client->{$method}('http://foo.com', ['X-Baz' => 'Bar'], $body, ['query' => ['a' => 'b']]);
        } else {
            $request = $client->{$method}('http://foo.com', ['X-Baz' => 'Bar'], ['query' => ['a' => 'b']]);
        }
        $this->assertEquals($method, $request->getMethod());
        $this->assertEquals('Bar', $request->getHeader('X-Baz'));
        $this->assertEquals('a=b', $request->getQuery());
        if ($body) {
            $this->assertEquals($body, $request->getBody());
        }
    }

    public function testClientMergesDefaultOptionsWithRequestOptions()
    {
        $client = new Client([
            'defaults' => [
                'headers' => ['Foo' => 'Bar'],
                'query' => ['baz' => 'bam'],
                'exceptions' => false
            ]
        ]);

        $e = null;
        $client->getEventDispatcher()->addListener(ClientEvents::CREATE_REQUEST, function ($ev) use (&$e) {
            $e = $ev;
        });

        $request = $client->createRequest('GET', 'http://foo.com?a=b', ['Hi' => 'there'], null, [
            'allow_redirects' => false,
            'query' => ['t' => 1],
            'headers' => ['1' => 'one']
        ]);

        $this->assertNotNull($e);
        $o = $e->getRequestOptions();
        $this->assertFalse($o['allow_redirects']);
        $this->assertFalse($o['exceptions']);
        $this->assertEquals('Bar', $request->getHeader('Foo'));
        $this->assertEquals('there', $request->getHeader('Hi'));
        $this->assertEquals('one', $request->getHeader('1'));
        $this->assertEquals('a=b&baz=bam&t=1', $request->getQuery());

        // Ensure the request uses a clone of the client event dispatcher
        $this->assertNotEmpty(
            $request->getEventDispatcher()->getListeners(ClientEvents::CREATE_REQUEST)
        );
    }

    public function testUsesBaseUrlWhenNoUrlIsSet()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/baz?bam=bar',
            $client->createRequest('GET')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/baz/bar/bam',
            $client->createRequest('GET', 'bar/bam')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrlViaUriTemplate()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/baz/bar/123',
            $client->createRequest('GET', ['bar/{bam}', ['bam' => '123']])->getUrl()
        );
    }

    public function testSettingAbsoluteUrlOverridesBaseUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/foo',
            $client->createRequest('GET', '/foo')->getUrl()
        );
    }

    public function testClientSendsRequests()
    {
        $response = new Response(200);
        $adapter = new MockAdapter();
        $adapter->setResponse($response);
        $client = new Client(['adapter' => $adapter]);
        $this->assertSame($response, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response->getEffectiveUrl());
    }

    public function testSendingRequestCanBeIntercepted()
    {
        $response = new Response(200);
        $response2 = new Response(200);
        $adapter = new MockAdapter();
        $adapter->setResponse($response);
        $client = new Client(['adapter' => $adapter]);
        $client->getEventDispatcher()->addListener(
            RequestEvents::BEFORE_SEND,
            function (RequestBeforeSendEvent $e) use ($response2) {
                $e->intercept($response2);
            }
        );
        $this->assertSame($response2, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response2->getEffectiveUrl());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage No response
     */
    public function testEnsuresResponseIsPresentAfterSending()
    {
        $adapter = $this->getMockBuilder('Guzzle\Http\Adapter\MockAdapter')
            ->setMethods(['send'])
            ->getMock();
        $adapter->expects($this->once())
            ->method('send');
        $client = new Client(['adapter' => $adapter]);
        $client->get('/');
    }

    public function testClientHandlesErrorsDuringBeforeSend()
    {
        $client = new Client();
        $client->getEventDispatcher()->addListener(RequestEvents::BEFORE_SEND, function ($e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $client->getEventDispatcher()->addListener(RequestEvents::ERROR, function ($e) {
            $e->intercept(new Response(200));
        });
        $this->assertEquals(200, $client->get('/')->getStatusCode());
    }

    /**
     * @expectedException \Guzzle\Http\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientHandlesErrorsDuringBeforeSendAndThrowsIfUnhandled()
    {
        $client = new Client();
        $client->getEventDispatcher()->addListener(RequestEvents::BEFORE_SEND, function ($e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $client->get('/');
    }

    /**
     * @expectedException \Guzzle\Http\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientHandlesErrorsDuringBeforeSendAndThrowsIfUnhandledAndWrapsThem()
    {
        $client = new Client();
        $client->getEventDispatcher()->addListener(RequestEvents::BEFORE_SEND, function ($e) {
            throw new \Exception('foo');
        });
        $client->get('/');
    }
}
