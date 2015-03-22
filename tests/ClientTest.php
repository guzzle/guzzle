<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
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
        $client = new Client(['handler' => new MockHandler(new Response())]);
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
        $mock = new MockHandler(new Response());
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
        $mock = new MockHandler(new Response());
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
        $mock = new MockHandler(new Response());
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock
        ]);
        $c->get('http://example.com', ['headers' => ['User-Agent' => 'bar']]);
        $this->assertEquals('bar', $mock->getLastRequest()->getHeader('User-Agent'));
    }

    public function testCanUnsetRequestOptionWithNull()
    {
        $mock = new MockHandler(new Response());
        $c = new Client([
            'headers' => ['foo' => 'bar'],
            'handler' => $mock
        ]);
        $c->get('http://example.com', ['headers' => null]);
        $this->assertFalse($mock->getLastRequest()->hasHeader('foo'));
    }
}
