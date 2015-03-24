<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testUsesDefaultHandler()
    {
        $client = new Client();
        Server::enqueue([new Response(200, ['Content-Length' => 0])]);
        $response = $client->get(Server::$url);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Magic request methods require a URI and optional options array
     */
    public function testValidatesArgsForMagicMethods()
    {
        $client = new Client();
        $client->get();
    }

    public function testCanSendMagicAsyncRequests()
    {
        $client = new Client();
        Server::flush();
        Server::enqueue([new Response(200, ['Content-Length' => 2], 'hi')]);
        $p = $client->getAsync(Server::$url, ['query' => ['test' => 'foo']]);
        $this->assertInstanceOf('GuzzleHttp\Promise\PromiseInterface', $p);
        $this->assertEquals(200, $p->wait()->getStatusCode());
        $received = Server::received(true);
        $this->assertCount(1, $received);
        $this->assertEquals('test=foo', $received[0]->getUri()->getQuery());
    }

    public function testCanSendSynchronously()
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);
        $request = new Request('GET', 'http://example.com');
        $r = $client->send($request);
        $this->assertInstanceOf('Psr\Http\Message\ResponseInterface', $r);
        $this->assertEquals(200, $r->getStatusCode());
    }

    public function testClientHasOptions()
    {
        $client = new Client([
            'base_uri' => 'http://foo.com',
            'timeout'  => 2,
            'headers'  => ['bar' => 'baz'],
            'handler'  => new MockHandler()
        ]);
        $base = $client->getDefaultOption('base_uri');
        $this->assertEquals('http://foo.com', (string) $base);
        $this->assertInstanceOf('GuzzleHttp\Psr7\Uri', $base);
        $this->assertNull($client->getDefaultOption('handler'));
        $this->assertEquals(2, $client->getDefaultOption('timeout'));
        $this->assertArrayHasKey('timeout', $client->getDefaultOption());
        $this->assertArrayHasKey('headers', $client->getDefaultOption());
    }

    public function testCanMergeOnBaseUri()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'base_uri' => 'http://foo.com/bar/',
            'handler'  => $mock
        ]);
        $client->get('baz');
        $this->assertEquals(
            'http://foo.com/bar/baz',
            $mock->getLastRequest()->getUri()
        );
    }

    public function testCanUseRelativeUriWithSend()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler'  => $mock,
            'base_uri' => 'http://bar.com'
        ]);
        $this->assertEquals('http://bar.com', (string) $client->getDefaultOption('base_uri'));
        $request = new Request('GET', '/baz');
        $client->send($request);
        $this->assertEquals(
            'http://bar.com/baz',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testMergesDefaultOptionsAndDoesNotOverwriteUa()
    {
        $c = new Client(['headers' => ['User-agent' => 'foo']]);
        $this->assertEquals(['User-agent' => 'foo'], $c->getDefaultOption('headers'));
        $this->assertInternalType('array', $c->getDefaultOption('allow_redirects'));
        $this->assertTrue($c->getDefaultOption('http_errors'));
        $this->assertTrue($c->getDefaultOption('decode_content'));
        $this->assertTrue($c->getDefaultOption('verify'));
    }

    public function testDoesNotOverwriteHeaderWithDefault()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock
        ]);
        $c->get('http://example.com', ['headers' => ['User-Agent' => 'bar']]);
        $this->assertEquals('bar', $mock->getLastRequest()->getHeader('User-Agent'));
    }

    public function testCanUnsetRequestOptionWithNull()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['foo' => 'bar'],
            'handler' => $mock
        ]);
        $c->get('http://example.com', ['headers' => null]);
        $this->assertFalse($mock->getLastRequest()->hasHeader('foo'));
    }

    public function testCanGetHandlerStack()
    {
        $client = new Client();
        $stack = $client->getHandlerStack();
        $this->assertInstanceOf('GuzzleHttp\HandlerStack', $stack);
        $this->assertTrue($stack->hasHandler());
    }

    public function testRewriteExceptionsToHttpErrors()
    {
        $client = new Client(['handler' => new MockHandler([new Response(404)])]);
        $res = $client->get('http://foo.com', ['exceptions' => false]);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testRewriteSaveToToSink()
    {
        $r = Psr7\stream_for(fopen('php://temp', 'r+'));
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['save_to' => $r]);
        $this->assertSame($r, $mock->getLastOptions()['sink']);
    }

    public function testAllowRedirectsCanBeTrue()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['allow_redirects' => true]);
        $this->assertInternalType('array',  $mock->getLastOptions()['allow_redirects']);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage allow_redirects must be true, false, or array
     */
    public function testValidatesAllowRedirects()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['allow_redirects' => 'foo']);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ClientException
     */
    public function testThrowsHttpErrorsByDefault()
    {
        $client = new Client(['handler' => new MockHandler([new Response(404)])]);
        $client->get('http://foo.com');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage cookies must be an array, true, or CookieJarInterface
     */
    public function testValidatesCookies()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['cookies' => 'foo']);
    }

    public function testSetCookieToTrueUsesSharedJar()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'foo=bar']),
            new Response()
        ]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['cookies' => true]);
        $client->get('http://foo.com', ['cookies' => true]);
        $this->assertEquals('foo=bar', $mock->getLastRequest()->getHeader('Cookie'));
    }

    public function testSetCookieToJar()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'foo=bar']),
            new Response()
        ]);
        $client = new Client(['handler' => $mock]);
        $jar = new CookieJar();
        $client->get('http://foo.com', ['cookies' => $jar]);
        $client->get('http://foo.com', ['cookies' => $jar]);
        $this->assertEquals('foo=bar', $mock->getLastRequest()->getHeader('Cookie'));
    }

    public function testSetCookieToArray()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['cookies' => ['foo' => 'bar']]);
        $this->assertEquals('foo=bar', $mock->getLastRequest()->getHeader('Cookie'));
    }

    public function testCanInjectIntoHandlerStackWithCallback()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', [
            'stack' => function (HandlerStack $s) use (&$called) {
                $s->push(Middleware::tap(function () use (&$called) {
                    $called = true;
                }));
            }
        ]);
        $this->assertTrue($called);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testStackOptionMustBeCallable()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['stack' => 'foo']);
    }

    public function testCanDisableContentDecoding()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => false]);
        $last = $mock->getLastRequest();
        $this->assertFalse($last->hasHeader('Accept-Encoding'));
        $this->assertFalse($mock->getLastOptions()['decode_content']);
    }

    public function testCanSetContentDecodingToValue()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => 'gzip']);
        $last = $mock->getLastRequest();
        $this->assertEquals('gzip', $last->getHeader('Accept-Encoding'));
        $this->assertEquals('gzip', $mock->getLastOptions()['decode_content']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesHeaders()
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['headers' => 'foo']);
    }

    public function testAddsBody()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['body' => 'foo']);
        $last = $mock->getLastRequest();
        $this->assertEquals('foo', (string) $last->getBody());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesQuery()
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => false]);
    }

    public function testQueryCanBeString()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => 'foo']);
        $this->assertEquals('foo', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testQueryCanBeArray()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => ['foo' => 'bar baz']]);
        $this->assertEquals('foo=bar%20baz', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testCanAddJsonData()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['json' => ['foo' => 'bar']]);
        $last = $mock->getLastRequest();
        $this->assertEquals('{"foo":"bar"}', (string) $mock->getLastRequest()->getBody());
        $this->assertEquals('application/json', $last->getHeader('Content-Type'));
    }

    public function testCanAddJsonDataWithoutOverwritingContentType()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, [
            'headers' => ['content-type' => 'foo'],
            'json'    => 'a'
        ]);
        $last = $mock->getLastRequest();
        $this->assertEquals('"a"', (string) $mock->getLastRequest()->getBody());
        $this->assertEquals('foo', $last->getHeader('Content-Type'));
    }

    public function testAuthCanBeTrue()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => false]);
        $last = $mock->getLastRequest();
        $this->assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeArrayForBasicAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b']]);
        $last = $mock->getLastRequest();
        $this->assertEquals('Basic YTpi', $last->getHeader('Authorization'));
    }

    public function testAuthCanBeArrayForDigestAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'digest']]);
        $last = $mock->getLastOptions();
        $this->assertEquals([
            CURLOPT_HTTPAUTH => 2,
            CURLOPT_USERPWD  => 'a:b'
        ], $last['config']['curl']);
    }

    public function testAuthCanBeCustomType()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => 'foo']);
        $last = $mock->getLastOptions();
        $this->assertEquals('foo', $last['auth']);
    }

    public function testCanAddFormFields()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'form_fields' => [
                'foo' => 'bar bam',
                'baz' => ['boo' => 'qux']
            ]
        ]);
        $last = $mock->getLastRequest();
        $this->assertEquals(
            'application/x-www-form-urlencoded',
            $last->getHeader('Content-Type')
        );
        $this->assertEquals(
            'foo=bar+bam&baz%5Bboo%5D=qux',
            (string) $last->getBody()
        );
    }

    public function testCanAddFormFieldsAndFiles()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'form_fields' => ['foo' => 'bar'],
            'form_files'  => [
                [
                    'name'     => 'test',
                    'contents' => fopen(__FILE__, 'r')
                ]
            ]
        ]);

        $last = $mock->getLastRequest();
        $this->assertContains(
            'multipart/form-data; boundary=',
            $last->getHeader('Content-Type')
        );

        $this->assertContains(
            'Content-Disposition: form-data; name="foo"',
            (string) $last->getBody()
        );

        $this->assertContains('bar', (string) $last->getBody());
        $this->assertContains(
            'Content-Disposition: form-data; name="test"; filename="ClientTest.php"',
            (string) $last->getBody()
        );
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

    public function testRequestSendsWithSync()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->request('GET', 'http://foo.com');
        $this->assertTrue($mock->getLastOptions()['sync']);
    }

    public function testSendSendsWithSync()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request('GET', 'http://foo.com'));
        $this->assertTrue($mock->getLastOptions()['sync']);
    }
}
