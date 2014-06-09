<?php

namespace GuzzleHttp\Tests\Adapter;

use GuzzleHttp\Adapter\StreamAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Tests\Server;

/**
 * @covers GuzzleHttp\Adapter\StreamAdapter
 */
class StreamAdapterTest extends \PHPUnit_Framework_TestCase
{
    public function testReturnsResponseForSuccessfulRequest()
    {
        Server::flush();
        Server::enqueue(
            "HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 2\r\n\r\nhi"
        );
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $response = $client->get('/', ['headers' => ['Foo' => 'Bar']]);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('Bar', $response->getHeader('Foo'));
        $this->assertEquals('2', $response->getHeader('Content-Length'));
        $this->assertEquals('hi', $response->getBody());
        $sent = Server::received(true)[0];
        $this->assertEquals('GET', $sent->getMethod());
        $this->assertEquals('/', $sent->getResource());
        $this->assertEquals('127.0.0.1:8125', $sent->getHeader('host'));
        $this->assertEquals('Bar', $sent->getHeader('foo'));
        $this->assertTrue($sent->hasHeader('user-agent'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage Error creating resource. [url] http://localhost:123 [proxy] tcp://localhost:1234
     */
    public function testThrowsExceptionsCaughtDuringTransfer()
    {
        Server::flush();
        $client = new Client([
            'adapter' => new StreamAdapter(new MessageFactory()),
        ]);
        $client->get('http://localhost:123', [
            'timeout' => 0.01,
            'proxy'   => 'tcp://localhost:1234'
        ]);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage URL is invalid: ftp://localhost:123
     */
    public function testEnsuresTheHttpProtocol()
    {
        Server::flush();
        $client = new Client([
            'adapter' => new StreamAdapter(new MessageFactory()),
        ]);
        $client->get('ftp://localhost:123');
    }

    public function testCanHandleExceptionsUsingEvents()
    {
        Server::flush();
        $client = new Client([
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $request = $client->createRequest('GET', Server::$url);
        $mockResponse = new Response(200);
        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use ($mockResponse) {
                $e->intercept($mockResponse);
            }
        );
        $this->assertSame($mockResponse, $client->send($request));
    }

    public function testEmitsAfterSendEvent()
    {
        $ee = null;
        Server::flush();
        Server::enqueue(
            "HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there"
        );
        $client = new Client(['adapter' => new StreamAdapter(new MessageFactory())]);
        $request = $client->createRequest('GET', Server::$url);
        $request->getEmitter()->on('complete', function ($e) use (&$ee) {
            $ee = $e;
        });
        $client->send($request);
        $this->assertInstanceOf('GuzzleHttp\Event\CompleteEvent', $ee);
        $this->assertSame($request, $ee->getRequest());
        $this->assertEquals(200, $ee->getResponse()->getStatusCode());
    }

    public function testStreamAttributeKeepsStreamOpen()
    {
        Server::flush();
        Server::enqueue(
            "HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there"
        );
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $response = $client->put('/foo', [
            'headers' => ['Foo' => 'Bar'],
            'body' => 'test',
            'stream' => true
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('8', $response->getHeader('Content-Length'));
        $body = $response->getBody();
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('HHVM has not implemented this?');
        }
        $this->assertEquals('http', $body->getMetadata()['wrapper_type']);
        $this->assertEquals(8, $body->getMetadata()['unread_bytes']);
        $this->assertEquals(Server::$url . 'foo', $body->getMetadata()['uri']);
        $this->assertEquals('hi', $body->read(2));
        $body->close();

        $sent = Server::received(true)[0];
        $this->assertEquals('PUT', $sent->getMethod());
        $this->assertEquals('/foo', $sent->getResource());
        $this->assertEquals('127.0.0.1:8125', $sent->getHeader('host'));
        $this->assertEquals('Bar', $sent->getHeader('foo'));
        $this->assertTrue($sent->hasHeader('user-agent'));
    }

    public function testDrainsResponseIntoTempStream()
    {
        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there");
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $response = $client->get('/');
        $body = $response->getBody();
        $this->assertEquals('php://temp', $body->getMetadata()['uri']);
        $this->assertEquals('hi', $body->read(2));
        $body->close();
    }

    public function testDrainsResponseIntoSaveToBody()
    {
        $r = fopen('php://temp', 'r+');
        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there");
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $response = $client->get('/', ['save_to' => $r]);
        $body = $response->getBody();
        $this->assertEquals('php://temp', $body->getMetadata()['uri']);
        $this->assertEquals('hi', $body->read(2));
        $this->assertEquals(' there', stream_get_contents($r));
        $body->close();
    }

    public function testDrainsResponseIntoSaveToBodyAtPath()
    {
        $tmpfname = tempnam('/tmp', 'save_to_path');
        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there");
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $response = $client->get('/', ['save_to' => $tmpfname]);
        $body = $response->getBody();
        $this->assertEquals($tmpfname, $body->getMetadata()['uri']);
        $this->assertEquals('hi', $body->read(2));
        $body->close();
        unlink($tmpfname);
    }

    public function testAddsGzipFilterIfAcceptHeaderIsPresent()
    {
        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there");
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $response = $client->get('/', [
            'headers' => ['Accept-Encoding' => 'gzip'],
            'stream' => true
        ]);
        $body = $response->getBody();
        $this->assertEquals('compress.zlib://http://127.0.0.1:8125/', $body->getMetadata()['uri']);
    }

    protected function getStreamFromBody(Stream $body)
    {
        $r = new \ReflectionProperty($body, 'stream');
        $r->setAccessible(true);

        return $r->getValue($body);
    }

    protected function getSendResult(array $opts)
    {
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there");
        $client = new Client(['adapter' => new StreamAdapter(new MessageFactory())]);

        return $client->get(Server::$url, $opts);
    }

    public function testAddsProxy()
    {
        $body = $this->getSendResult(['stream' => true, 'proxy' => '127.0.0.1:8125'])->getBody();
        $opts = stream_context_get_options($this->getStreamFromBody($body));
        $this->assertEquals('127.0.0.1:8125', $opts['http']['proxy']);
        $this->assertTrue($opts['http']['request_fulluri']);
    }

    public function testAddsTimeout()
    {
        $body = $this->getSendResult(['stream' => true, 'timeout' => 200])->getBody();
        $opts = stream_context_get_options($this->getStreamFromBody($body));
        $this->assertEquals(200, $opts['http']['timeout']);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage SSL certificate authority file not found: /does/not/exist
     */
    public function testVerifiesVerifyIsValidIfPath()
    {
        (new Client([
            'adapter' => new StreamAdapter(new MessageFactory()),
            'base_url' => Server::$url,
            'defaults' => ['verify' => '/does/not/exist']
        ]))->get('/');
    }

    public function testVerifyCanBeDisabled()
    {
        Server::enqueue("HTTP/1.1 200\r\nContent-Length: 0\r\n\r\n");
        (new Client([
            'adapter' => new StreamAdapter(new MessageFactory()),
            'base_url' => Server::$url,
            'defaults' => ['verify' => false]
        ]))->get('/');
    }

    public function testVerifyCanBeSetToPath()
    {
        $path = __DIR__ . '/../../src/cacert.pem';
        $this->assertFileExists($path);
        $body = $this->getSendResult(['stream' => true, 'verify' => $path])->getBody();
        $opts = stream_context_get_options($this->getStreamFromBody($body));
        $this->assertEquals(true, $opts['http']['verify_peer']);
        $this->assertEquals($path, $opts['http']['cafile']);
        $this->assertTrue(file_exists($opts['http']['cafile']));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage SSL certificate not found: /does/not/exist
     */
    public function testVerifiesCertIfValidPath()
    {
        (new Client([
            'adapter' => new StreamAdapter(new MessageFactory()),
            'base_url' => Server::$url,
            'defaults' => ['cert' => '/does/not/exist']
        ]))->get('/');
    }

    public function testCanSetPasswordWhenSettingCert()
    {
        $path = __DIR__ . '/../../src/cacert.pem';
        $body = $this->getSendResult(['stream' => true, 'cert' => [$path, 'foo']])->getBody();
        $opts = stream_context_get_options($this->getStreamFromBody($body));
        $this->assertEquals($path, $opts['http']['local_cert']);
        $this->assertEquals('foo', $opts['http']['passphrase']);
    }

    public function testDebugAttributeWritesStreamInfoToTempBufferByDefault()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('HHVM has not implemented this?');
            return;
        }

        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 8\r\n\r\nhi there");
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        ob_start();
        $client->get('/', ['debug' => true]);
        $contents = ob_get_clean();
        $this->assertContains('<http://127.0.0.1:8125/> [CONNECT]', $contents);
        $this->assertContains('<http://127.0.0.1:8125/> [FILE_SIZE_IS]', $contents);
        $this->assertContains('<http://127.0.0.1:8125/> [PROGRESS]', $contents);
    }

    public function testDebugAttributeWritesStreamInfoToBuffer()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('HHVM has not implemented this?');
            return;
        }

        $buffer = fopen('php://temp', 'r+');
        Server::flush();
        Server::enqueue("HTTP/1.1 200 OK\r\nContent-Length: 8\r\nContent-Type: text/plain\r\n\r\nhi there");
        $client = new Client([
            'base_url' => Server::$url,
            'adapter' => new StreamAdapter(new MessageFactory())
        ]);
        $client->get('/', ['debug' => $buffer]);
        fseek($buffer, 0);
        $contents = stream_get_contents($buffer);
        $this->assertContains('<http://127.0.0.1:8125/> [CONNECT]', $contents);
        $this->assertContains('<http://127.0.0.1:8125/> [FILE_SIZE_IS] message: "Content-Length: 8"', $contents);
        $this->assertContains('<http://127.0.0.1:8125/> [PROGRESS] bytes_max: "8"', $contents);
        $this->assertContains('<http://127.0.0.1:8125/> [MIME_TYPE_IS] message: "text/plain"', $contents);
    }

    public function testAddsProxyByProtocol()
    {
        $url = str_replace('http', 'tcp', Server::$url);
        $body = $this->getSendResult(['stream' => true, 'proxy' => ['http' => $url]])->getBody();
        $opts = stream_context_get_options($this->getStreamFromBody($body));
        $this->assertEquals($url, $opts['http']['proxy']);
    }

    public function testPerformsShallowMergeOfCustomContextOptions()
    {
        $body = $this->getSendResult([
            'stream' => true,
            'config' => [
                'stream_context' => [
                    'http' => [
                        'request_fulluri' => true,
                        'method' => 'HEAD'
                    ],
                    'socket' => [
                        'bindto' => '127.0.0.1:0'
                    ],
                    'ssl' => [
                        'verify_peer' => false
                    ]
                ]
            ]
        ])->getBody();

        $opts = stream_context_get_options($this->getStreamFromBody($body));
        $this->assertEquals('HEAD', $opts['http']['method']);
        $this->assertTrue($opts['http']['request_fulluri']);
        $this->assertFalse($opts['ssl']['verify_peer']);
        $this->assertEquals('127.0.0.1:0', $opts['socket']['bindto']);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage stream_context must be an array
     */
    public function testEnsuresThatStreamContextIsAnArray()
    {
        $this->getSendResult([
            'stream' => true,
            'config' => ['stream_context' => 'foo']
        ]);
    }
}
