<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Ring\Client\MockHandler;
use GuzzleHttp\Ring\Future\FutureArray;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Subscriber\Mock;
use React\Promise\Deferred;

/**
 * @covers GuzzleHttp\Client
 */
class ClientTest extends \PHPUnit_Framework_TestCase
{
    /** @callable */
    private $ma;

    public function setup()
    {
        $this->ma = function () {
            throw new \RuntimeException('Should not have been called.');
        };
    }

    public function testProvidesDefaultUserAgent()
    {
        $ua = Client::getDefaultUserAgent();
        $this->assertEquals(1, preg_match('#^Guzzle/.+ curl/.+ PHP/.+$#', $ua));
    }

    public function testUsesDefaultDefaultOptions()
    {
        $client = new Client();
        $this->assertTrue($client->getDefaultOption('allow_redirects'));
        $this->assertTrue($client->getDefaultOption('exceptions'));
        $this->assertTrue($client->getDefaultOption('verify'));
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
        $this->assertTrue($client->getDefaultOption('verify'));
        $this->assertEquals(['foo' => 'bar'], $client->getDefaultOption('query'));
    }

    public function testCanSpecifyBaseUrl()
    {
        $this->assertSame('', (new Client())->getBaseUrl());
        $this->assertEquals('http://foo', (new Client([
            'base_url' => 'http://foo'
        ]))->getBaseUrl());
    }

    public function testCanSpecifyBaseUrlUriTemplate()
    {
        $client = new Client(['base_url' => ['http://foo.com/{var}/', ['var' => 'baz']]]);
        $this->assertEquals('http://foo.com/baz/', $client->getBaseUrl());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyHandler()
    {
        $client = new Client(['handler' => function () {
                throw new \Exception('Foo');
            }]);
        $client->get('http://httpbin.org');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyHandlerAsAdapter()
    {
        $client = new Client(['adapter' => function () {
            throw new \Exception('Foo');
        }]);
        $client->get('http://httpbin.org');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyMessageFactory()
    {
        $factory = $this->getMockBuilder('GuzzleHttp\Message\MessageFactoryInterface')
            ->setMethods(['createRequest'])
            ->getMockForAbstractClass();
        $factory->expects($this->once())
            ->method('createRequest')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['message_factory' => $factory]);
        $client->get();
    }

    public function testCanSpecifyEmitter()
    {
        $emitter = $this->getMockBuilder('GuzzleHttp\Event\EmitterInterface')
            ->setMethods(['listeners'])
            ->getMockForAbstractClass();
        $emitter->expects($this->once())
            ->method('listeners')
            ->will($this->returnValue('foo'));

        $client = new Client(['emitter' => $emitter]);
        $this->assertEquals('foo', $client->getEmitter()->listeners());
    }

    public function testAddsDefaultUserAgentHeaderWithDefaultOptions()
    {
        $client = new Client(['defaults' => ['allow_redirects' => false]]);
        $this->assertFalse($client->getDefaultOption('allow_redirects'));
        $this->assertEquals(
            ['User-Agent' => Client::getDefaultUserAgent()],
            $client->getDefaultOption('headers')
        );
    }

    public function testAddsDefaultUserAgentHeaderWithoutDefaultOptions()
    {
        $client = new Client();
        $this->assertEquals(
            ['User-Agent' => Client::getDefaultUserAgent()],
            $client->getDefaultOption('headers')
        );
    }

    private function getRequestClient()
    {
        $client = $this->getMockBuilder('GuzzleHttp\Client')
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
            $request = $client->{$method}('http://foo.com', [
                'headers' => ['X-Baz' => 'Bar'],
                'body' => $body,
                'query' => ['a' => 'b']
            ]);
        } else {
            $request = $client->{$method}('http://foo.com', [
                'headers' => ['X-Baz' => 'Bar'],
                'query' => ['a' => 'b']
            ]);
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
        $f = $this->getMockBuilder('GuzzleHttp\Message\MessageFactoryInterface')
            ->setMethods(array('createRequest'))
            ->getMockForAbstractClass();

        $o = null;
        // Intercept the creation
        $f->expects($this->once())
            ->method('createRequest')
            ->will($this->returnCallback(
                function ($method, $url, array $options = []) use (&$o) {
                    $o = $options;
                    return (new MessageFactory())->createRequest($method, $url, $options);
                }
            ));

        $client = new Client([
            'message_factory' => $f,
            'defaults' => [
                'headers' => ['Foo' => 'Bar'],
                'query' => ['baz' => 'bam'],
                'exceptions' => false
            ]
        ]);

        $request = $client->createRequest('GET', 'http://foo.com?a=b', [
            'headers' => ['Hi' => 'there', '1' => 'one'],
            'allow_redirects' => false,
            'query' => ['t' => 1]
        ]);

        $this->assertFalse($o['allow_redirects']);
        $this->assertFalse($o['exceptions']);
        $this->assertEquals('Bar', $request->getHeader('Foo'));
        $this->assertEquals('there', $request->getHeader('Hi'));
        $this->assertEquals('one', $request->getHeader('1'));
        $this->assertEquals('a=b&baz=bam&t=1', $request->getQuery());
    }

    public function testClientMergesDefaultHeadersCaseInsensitively()
    {
        $client = new Client(['defaults' => ['headers' => ['Foo' => 'Bar']]]);
        $request = $client->createRequest('GET', 'http://foo.com?a=b', [
            'headers' => ['foo' => 'custom', 'user-agent' => 'test']
        ]);
        $this->assertEquals('test', $request->getHeader('User-Agent'));
        $this->assertEquals('custom', $request->getHeader('Foo'));
    }

    public function testDoesNotOverwriteExistingUA()
    {
        $client = new Client(['defaults' => [
            'headers' => ['User-Agent' => 'test']
        ]]);
        $this->assertEquals(
            ['User-Agent' => 'test'],
            $client->getDefaultOption('headers')
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
            'http://www.foo.com/bar/bam',
            $client->createRequest('GET', 'bar/bam')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrlViaUriTemplate()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/bar/123',
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

    public function testSettingAbsoluteUriTemplateOverridesBaseUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://goo.com/1',
            $client->createRequest(
                'GET',
                ['http://goo.com/{bar}', ['bar' => '1']]
            )->getUrl()
        );
    }

    public function testCanSetRelativeUrlStartingWithHttp()
    {
        $client = new Client(['base_url' => 'http://www.foo.com']);
        $this->assertEquals(
            'http://www.foo.com/httpfoo',
            $client->createRequest('GET', 'httpfoo')->getUrl()
        );
    }

    public function testClientSendsRequests()
    {
        $mock = new MockHandler(['status' => 200, 'headers' => []]);
        $client = new Client(['handler' => $mock]);
        $response = $client->get('http://test.com');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://test.com', $response->getEffectiveUrl());
    }

    public function testSendingRequestCanBeIntercepted()
    {
        $response = new Response(200);
        $client = new Client(['handler' => $this->ma]);
        $client->getEmitter()->on(
            'before',
            function (BeforeEvent $e) use ($response) {
                $e->intercept($response);
            }
        );
        $this->assertSame($response, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response->getEffectiveUrl());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage Argument 1 passed to GuzzleHttp\Message\FutureResponse::proxy() must implement interface GuzzleHttp\Ring\Future\FutureInterface
     */
    public function testEnsuresResponseIsPresentAfterSending()
    {
        $handler = function () {};
        $client = new Client(['handler' => $handler]);
        $client->get('http://httpbin.org');
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage Waiting did not resolve future
     */
    public function testEnsuresResponseIsPresentAfterDereferencing()
    {
        $deferred = new Deferred();
        $handler = new MockHandler(function () use ($deferred) {
            return new FutureArray(
                $deferred->promise(),
                function () {}
            );
        });
        $client = new Client(['handler' => $handler]);
        $response = $client->get('http://httpbin.org');
        $response->wait();
    }

    public function testClientHandlesErrorsDuringBeforeSend()
    {
        $client = new Client();
        $client->getEmitter()->on('before', function ($e) {
            throw new \Exception('foo');
        });
        $client->getEmitter()->on('error', function (ErrorEvent $e) {
            $e->intercept(new Response(200));
        });
        $this->assertEquals(
            200,
            $client->get('http://test.com')->getStatusCode()
        );
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientHandlesErrorsDuringBeforeSendAndThrowsIfUnhandled()
    {
        $client = new Client();
        $client->getEmitter()->on('before', function (BeforeEvent $e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $client->get('http://httpbin.org');
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientWrapsExceptions()
    {
        $client = new Client();
        $client->getEmitter()->on('before', function (BeforeEvent $e) {
            throw new \Exception('foo');
        });
        $client->get('http://httpbin.org');
    }

    public function testCanInjectResponseForFutureError()
    {
        $calledFuture = false;
        $deferred = new Deferred();
        $future = new FutureArray(
            $deferred->promise(),
            function () use ($deferred, &$calledFuture) {
                $calledFuture = true;
                $deferred->resolve(['error' => new \Exception('Noo!')]);
            }
        );
        $mock = new MockHandler($future);
        $client = new Client(['handler' => $mock]);
        $called = 0;
        $response = $client->get('http://localhost:123/foo', [
            'future' => true,
            'events' => [
                'error' => function (ErrorEvent $e) use (&$called) {
                    $called++;
                    $e->intercept(new Response(200));
                }
            ]
        ]);
        $this->assertEquals(0, $called);
        $this->assertInstanceOf('GuzzleHttp\Message\FutureResponse', $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($calledFuture);
        $this->assertEquals(1, $called);
    }

    public function testCanReturnFutureResults()
    {
        $called = false;
        $deferred = new Deferred();
        $future = new FutureArray(
            $deferred->promise(),
            function () use ($deferred, &$called) {
                $called = true;
                $deferred->resolve(['status' => 201, 'headers' => []]);
            }
        );
        $mock = new MockHandler($future);
        $client = new Client(['handler' => $mock]);
        $response = $client->get('http://localhost:123/foo', ['future' => true]);
        $this->assertFalse($called);
        $this->assertInstanceOf('GuzzleHttp\Message\FutureResponse', $response);
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertTrue($called);
    }

    public function testThrowsExceptionsWhenDereferenced()
    {
        $calledFuture = false;
        $deferred = new Deferred();
        $future = new FutureArray(
            $deferred->promise(),
            function () use ($deferred, &$calledFuture) {
                $calledFuture = true;
                $deferred->resolve(['error' => new \Exception('Noop!')]);
            }
        );
        $client = new Client(['handler' => new MockHandler($future)]);
        try {
            $res = $client->get('http://localhost:123/foo', ['future' => true]);
            $res->wait();
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertEquals(1, $calledFuture);
        }
    }

    /**
     * @expectedExceptionMessage Noo!
     * @expectedException \GuzzleHttp\Exception\RequestException
     */
    public function testThrowsExceptionsSynchronously()
    {
        $client = new Client([
            'handler' => new MockHandler(['error' => new \Exception('Noo!')])
        ]);
        $client->get('http://localhost:123/foo');
    }

    public function testCanSetDefaultValues()
    {
        $client = new Client(['foo' => 'bar']);
        $client->setDefaultOption('headers/foo', 'bar');
        $this->assertNull($client->getDefaultOption('foo'));
        $this->assertEquals('bar', $client->getDefaultOption('headers/foo'));
    }

    public function testSendsAllInParallel()
    {
        $client = new Client();
        $client->getEmitter()->attach(new Mock([
            new Response(200),
            new Response(201),
            new Response(202),
        ]));
        $history = new History();
        $client->getEmitter()->attach($history);

        $requests = [
            $client->createRequest('GET', 'http://test.com'),
            $client->createRequest('POST', 'http://test.com'),
            $client->createRequest('PUT', 'http://test.com')
        ];

        $client->sendAll($requests);
        $requests = array_map(function($r) {
            return $r->getMethod();
        }, $history->getRequests());
        $this->assertContains('GET', $requests);
        $this->assertContains('POST', $requests);
        $this->assertContains('PUT', $requests);
    }

    public function testCanDisableAuthPerRequest()
    {
        $client = new Client(['defaults' => ['auth' => 'foo']]);
        $request = $client->createRequest('GET', 'http://test.com');
        $this->assertEquals('foo', $request->getConfig()['auth']);
        $request = $client->createRequest('GET', 'http://test.com', ['auth' => null]);
        $this->assertFalse($request->getConfig()->hasKey('auth'));
    }

    public function testUsesProxyEnvironmentVariables()
    {
        $http = getenv('HTTP_PROXY');
        $https = getenv('HTTPS_PROXY');

        $client = new Client();
        $this->assertNull($client->getDefaultOption('proxy'));

        putenv('HTTP_PROXY=127.0.0.1');
        $client = new Client();
        $this->assertEquals(
            ['http' => '127.0.0.1'],
            $client->getDefaultOption('proxy')
        );

        putenv('HTTPS_PROXY=127.0.0.2');
        $client = new Client();
        $this->assertEquals(
            ['http' => '127.0.0.1', 'https' => '127.0.0.2'],
            $client->getDefaultOption('proxy')
        );

        putenv("HTTP_PROXY=$http");
        putenv("HTTPS_PROXY=$https");
    }

    public function testReturnsFutureForErrorWhenRequested()
    {
        $client = new Client(['handler' => new MockHandler(['status' => 404])]);
        $request = $client->createRequest('GET', 'http://localhost:123/foo', [
            'future' => true
        ]);
        $res = $client->send($request);
        $this->assertInstanceOf('GuzzleHttp\Message\FutureResponse', $res);
        try {
            $res->wait();
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertContains('404', $e->getMessage());
        }
    }

    public function testReturnsFutureForResponseWhenRequested()
    {
        $client = new Client(['handler' => new MockHandler(['status' => 200])]);
        $request = $client->createRequest('GET', 'http://localhost:123/foo', [
            'future' => true
        ]);
        $res = $client->send($request);
        $this->assertInstanceOf('GuzzleHttp\Message\FutureResponse', $res);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
