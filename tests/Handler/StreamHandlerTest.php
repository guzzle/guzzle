<?php
namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Tests\Server;

/**
 * @covers \GuzzleHttp\Handler\StreamHandler
 */
class StreamHandlerTest extends \PHPUnit_Framework_TestCase
{
    private function queueRes()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => 8,
            ], 'hi there')
        ]);
    }

    public function testReturnsResponseForSuccessfulRequest()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $response = $handler(
            new Request('GET', Server::$url, ['Foo' => 'Bar']),
            []
        )->wait();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('Bar', $response->getHeader('Foo'));
        $this->assertEquals('8', $response->getHeader('Content-Length'));
        $this->assertEquals('hi there', (string) $response->getBody());
        $sent = Server::received()[0];
        $this->assertEquals('GET', $sent->getMethod());
        $this->assertEquals('/', $sent->getUri()->getPath());
        $this->assertEquals('127.0.0.1:8126', $sent->getHeader('Host'));
        $this->assertEquals('Bar', $sent->getHeader('foo'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ConnectException
     */
    public function testAddsErrorToResponse()
    {
        $handler = new StreamHandler();
        $handler(
            new Request('GET', 'http://localhost:123'),
            ['timeout' => 0.01]
        )->wait();
    }

    public function testStreamAttributeKeepsStreamOpen()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request(
            'PUT',
            Server::$url . 'foo?baz=bar',
            ['Foo' => 'Bar'],
            'test'
        );
        $response = $handler($request, ['stream' => true])->wait();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('8', $response->getHeader('Content-Length'));
        $body = $response->getBody();
        $stream = $body->detach();
        $this->assertTrue(is_resource($stream));
        $this->assertEquals('http', stream_get_meta_data($stream)['wrapper_type']);
        $this->assertEquals('hi there', stream_get_contents($stream));
        fclose($stream);
        $sent = Server::received()[0];
        $this->assertEquals('PUT', $sent->getMethod());
        $this->assertEquals('http://127.0.0.1:8126/foo?baz=bar', (string) $sent->getUri());
        $this->assertEquals('Bar', $sent->getHeader('Foo'));
        $this->assertEquals('test', (string) $sent->getBody());
    }

    public function testDrainsResponseIntoTempStream()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        $this->assertEquals('php://temp', stream_get_meta_data($stream)['uri']);
        $this->assertEquals('hi', fread($stream, 2));
        fclose($stream);
    }

    public function testDrainsResponseIntoSaveToBody()
    {
        $r = fopen('php://temp', 'r+');
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['sink' => $r])->wait();
        $body = $response->getBody()->detach();
        $this->assertEquals('php://temp', stream_get_meta_data($body)['uri']);
        $this->assertEquals('hi', fread($body, 2));
        $this->assertEquals(' there', stream_get_contents($r));
        fclose($r);
    }

    public function testDrainsResponseIntoSaveToBodyAtPath()
    {
        $tmpfname = tempnam('/tmp', 'save_to_path');
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['sink' => $tmpfname])->wait();
        $body = $response->getBody();
        $this->assertEquals($tmpfname, $body->getMetadata('uri'));
        $this->assertEquals('hi', $body->read(2));
        $body->close();
        unlink($tmpfname);
    }

    public function testAutomaticallyDecompressGzip()
    {
        Server::flush();
        $content = gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length'   => strlen($content),
            ], $content)
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();
        $this->assertEquals('test', (string) $response->getBody());
    }

    public function testDoesNotForceGzipDecode()
    {
        Server::flush();
        $content = gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length'   => strlen($content),
            ], $content)
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => false])->wait();
        $this->assertSame($content, (string) $response->getBody());
    }

    public function testProtocolVersion()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], null, '1.0');
        $handler($request, []);
        $this->assertEquals('1.0', Server::received()[0]->getProtocolVersion());
    }

    protected function getSendResult(array $opts)
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $opts['stream'] = true;
        $request = new Request('GET', Server::$url);
        return $handler($request, $opts)->wait();
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ConnectException
     * @expectedExceptionMessage Connection refused
     */
    public function testAddsProxy()
    {
        $this->getSendResult(['proxy' => '127.0.0.1:8125']);
    }

    public function testAddsTimeout()
    {
        $res = $this->getSendResult(['stream' => true, 'timeout' => 200]);
        $opts = stream_context_get_options($res->getBody()->detach());
        $this->assertEquals(200, $opts['http']['timeout']);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage SSL CA bundle not found: /does/not/exist
     */
    public function testVerifiesVerifyIsValidIfPath()
    {
        $this->getSendResult(['verify' => '/does/not/exist']);
    }

    public function testVerifyCanBeDisabled()
    {
        $this->getSendResult(['verify' => false]);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage SSL certificate not found: /does/not/exist
     */
    public function testVerifiesCertIfValidPath()
    {
        $this->getSendResult(['cert' => '/does/not/exist']);
    }

    public function testVerifyCanBeSetToPath()
    {
        $path = $path = \GuzzleHttp\default_ca_bundle();
        $res = $this->getSendResult(['verify' => $path]);
        $opts = stream_context_get_options($res->getBody()->detach());
        $this->assertEquals(true, $opts['ssl']['verify_peer']);
        $this->assertEquals($path, $opts['ssl']['cafile']);
        $this->assertTrue(file_exists($opts['ssl']['cafile']));
    }

    public function testUsesSystemDefaultBundle()
    {
        $path = $path = \GuzzleHttp\default_ca_bundle();
        $res = $this->getSendResult(['verify' => true]);
        $opts = stream_context_get_options($res->getBody()->detach());
        if (PHP_VERSION_ID < 50600) {
            $this->assertEquals($path, $opts['ssl']['cafile']);
        }
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage Invalid verify request option
     */
    public function testEnsuresVerifyOptionIsValid()
    {
        $this->getSendResult(['verify' => 10]);
    }

    public function testCanSetPasswordWhenSettingCert()
    {
        $path = __FILE__;
        $res = $this->getSendResult(['cert' => [$path, 'foo']]);
        $opts = stream_context_get_options($res->getBody()->detach());
        $this->assertEquals($path, $opts['ssl']['local_cert']);
        $this->assertEquals('foo', $opts['ssl']['passphrase']);
    }
}
