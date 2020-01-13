<?php
namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\StreamHandler
 */
class StreamHandlerTest extends TestCase
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
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        self::assertSame('hi there', (string) $response->getBody());
        $sent = Server::received()[0];
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('/', $sent->getUri()->getPath());
        self::assertSame('127.0.0.1:8126', $sent->getHeaderLine('Host'));
        self::assertSame('Bar', $sent->getHeaderLine('foo'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
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
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertInternalType('resource', $stream);
        self::assertSame('http', stream_get_meta_data($stream)['wrapper_type']);
        self::assertSame('hi there', stream_get_contents($stream));
        fclose($stream);
        $sent = Server::received()[0];
        self::assertSame('PUT', $sent->getMethod());
        self::assertSame('http://127.0.0.1:8126/foo?baz=bar', (string) $sent->getUri());
        self::assertSame('Bar', $sent->getHeaderLine('Foo'));
        self::assertSame('test', (string) $sent->getBody());
    }

    public function testDrainsResponseIntoTempStream()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('php://temp', stream_get_meta_data($stream)['uri']);
        self::assertSame('hi', fread($stream, 2));
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
        self::assertSame('php://temp', stream_get_meta_data($body)['uri']);
        self::assertSame('hi', fread($body, 2));
        self::assertSame(' there', stream_get_contents($r));
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
        self::assertSame($tmpfname, $body->getMetadata('uri'));
        self::assertSame('hi', $body->read(2));
        $body->close();
        unlink($tmpfname);
    }

    public function testDrainsResponseIntoSaveToBodyAtNonExistentPath()
    {
        $tmpfname = tempnam('/tmp', 'save_to_path');
        unlink($tmpfname);
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['sink' => $tmpfname])->wait();
        $body = $response->getBody();
        self::assertSame($tmpfname, $body->getMetadata('uri'));
        self::assertSame('hi', $body->read(2));
        $body->close();
        unlink($tmpfname);
    }

    public function testDrainsResponseAndReadsOnlyContentLengthBytes()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => 8,
            ], 'hi there... This has way too much data!')
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('hi there', stream_get_contents($stream));
        fclose($stream);
    }

    public function testDoesNotDrainWhenHeadRequest()
    {
        Server::flush();
        // Say the content-length is 8, but return no response.
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => 8,
            ], '')
        ]);
        $handler = new StreamHandler();
        $request = new Request('HEAD', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('', stream_get_contents($stream));
        fclose($stream);
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
        self::assertSame('test', (string) $response->getBody());
        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertTrue(!$response->hasHeader('content-length') || $response->getHeaderLine('content-length') == $response->getBody()->getSize());
    }

    public function testReportsOriginalSizeAndContentEncodingAfterDecoding()
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

        self::assertSame(
            'gzip',
            $response->getHeaderLine('x-encoded-content-encoding')
        );
        self::assertSame(
            strlen($content),
            (int) $response->getHeaderLine('x-encoded-content-length')
        );
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
        self::assertSame($content, (string) $response->getBody());
        self::assertSame('gzip', $response->getHeaderLine('content-encoding'));
        self::assertEquals(strlen($content), $response->getHeaderLine('content-length'));
    }

    public function testProtocolVersion()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], null, '1.0');
        $handler($request, []);
        self::assertSame('1.0', Server::received()[0]->getProtocolVersion());
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

    public function testAddsProxyByProtocol()
    {
        $url = str_replace('http', 'tcp', Server::$url);
        // Workaround until #1823 is fixed properly
        $url = rtrim($url, '/');
        $res = $this->getSendResult(['proxy' => ['http' => $url]]);
        $opts = stream_context_get_options($res->getBody()->detach());
        self::assertSame($url, $opts['http']['proxy']);
    }

    public function testAddsProxyButHonorsNoProxy()
    {
        $url = str_replace('http', 'tcp', Server::$url);
        $res = $this->getSendResult(['proxy' => [
            'http' => $url,
            'no'   => ['*']
        ]]);
        $opts = stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('proxy', $opts['http']);
    }

    public function testAddsTimeout()
    {
        $res = $this->getSendResult(['stream' => true, 'timeout' => 200]);
        $opts = stream_context_get_options($res->getBody()->detach());
        self::assertEquals(200, $opts['http']['timeout']);
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
        $handler = $this->getSendResult(['verify' => false]);
        self::assertInstanceOf('GuzzleHttp\Psr7\Response', $handler);
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
        self::assertTrue($opts['ssl']['verify_peer']);
        self::assertTrue($opts['ssl']['verify_peer_name']);
        self::assertSame($path, $opts['ssl']['cafile']);
        self::assertFileExists($opts['ssl']['cafile']);
    }

    public function testUsesSystemDefaultBundle()
    {
        $path = $path = \GuzzleHttp\default_ca_bundle();
        $res = $this->getSendResult(['verify' => true]);
        $opts = stream_context_get_options($res->getBody()->detach());
        if (PHP_VERSION_ID < 50600) {
            self::assertSame($path, $opts['ssl']['cafile']);
        } else {
            self::assertArrayNotHasKey('cafile', $opts['ssl']);
        }
    }

    /**
     * @expectedException \InvalidArgumentException
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
        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testDebugAttributeWritesToStream()
    {
        $this->queueRes();
        $f = fopen('php://temp', 'w+');
        $this->getSendResult(['debug' => $f]);
        fseek($f, 0);
        $contents = stream_get_contents($f);
        self::assertContains('<GET http://127.0.0.1:8126/> [CONNECT]', $contents);
        self::assertContains('<GET http://127.0.0.1:8126/> [FILE_SIZE_IS]', $contents);
        self::assertContains('<GET http://127.0.0.1:8126/> [PROGRESS]', $contents);
    }

    public function testDebugAttributeWritesStreamInfoToBuffer()
    {
        $called = false;
        $this->queueRes();
        $buffer = fopen('php://temp', 'r+');
        $this->getSendResult([
            'progress' => function () use (&$called) {
                $called = true;
            },
            'debug' => $buffer,
        ]);
        fseek($buffer, 0);
        $contents = stream_get_contents($buffer);
        self::assertContains('<GET http://127.0.0.1:8126/> [CONNECT]', $contents);
        self::assertContains('<GET http://127.0.0.1:8126/> [FILE_SIZE_IS] message: "Content-Length: 8"', $contents);
        self::assertContains('<GET http://127.0.0.1:8126/> [PROGRESS] bytes_max: "8"', $contents);
        self::assertTrue($called);
    }

    public function testEmitsProgressInformation()
    {
        $called = [];
        $this->queueRes();
        $this->getSendResult([
            'progress' => function () use (&$called) {
                $called[] = func_get_args();
            },
        ]);
        self::assertNotEmpty($called);
        self::assertEquals(8, $called[0][0]);
        self::assertEquals(0, $called[0][1]);
    }

    public function testEmitsProgressInformationAndDebugInformation()
    {
        $called = [];
        $this->queueRes();
        $buffer = fopen('php://memory', 'w+');
        $this->getSendResult([
            'debug'    => $buffer,
            'progress' => function () use (&$called) {
                $called[] = func_get_args();
            },
        ]);
        self::assertNotEmpty($called);
        self::assertEquals(8, $called[0][0]);
        self::assertEquals(0, $called[0][1]);
        rewind($buffer);
        self::assertNotEmpty(stream_get_contents($buffer));
        fclose($buffer);
    }

    public function testPerformsShallowMergeOfCustomContextOptions()
    {
        $res = $this->getSendResult([
            'stream_context' => [
                'http' => [
                    'request_fulluri' => true,
                    'method' => 'HEAD',
                ],
                'socket' => [
                    'bindto' => '127.0.0.1:0',
                ],
                'ssl' => [
                    'verify_peer' => false,
                ],
            ],
        ]);
        $opts = stream_context_get_options($res->getBody()->detach());
        self::assertSame('HEAD', $opts['http']['method']);
        self::assertTrue($opts['http']['request_fulluri']);
        self::assertSame('127.0.0.1:0', $opts['socket']['bindto']);
        self::assertFalse($opts['ssl']['verify_peer']);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage stream_context must be an array
     */
    public function testEnsuresThatStreamContextIsAnArray()
    {
        $this->getSendResult(['stream_context' => 'foo']);
    }

    public function testDoesNotAddContentTypeByDefault()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, ['Content-Length' => 3], 'foo');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals('', $req->getHeaderLine('Content-Type'));
        self::assertEquals(3, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthByDefault()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], 'foo');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(3, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthEvenWhenEmpty()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testSupports100Continue()
    {
        Server::flush();
        $response = new Response(200, ['Test' => 'Hello', 'Content-Length' => '4'], 'test');
        Server::enqueue([$response]);
        $request = new Request('PUT', Server::$url, ['Expect' => '100-Continue'], 'test');
        $handler = new StreamHandler();
        $response = $handler($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello', $response->getHeaderLine('Test'));
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
        self::assertSame('test', (string) $response->getBody());
    }

    public function testDoesSleep()
    {
        $response = new response(200);
        Server::enqueue([$response]);
        $a = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $s = Utils::currentTime();
        $a($request, ['delay' => 0.1])->wait();
        self::assertGreaterThan(0.0001, Utils::currentTime() - $s);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresOnHeadersIsCallable()
    {
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $handler($req, ['on_headers' => 'error!']);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage An error was encountered during the on_headers event
     * @expectedExceptionMessage test
     */
    public function testRejectsPromiseWhenOnHeadersFails()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123')
        ]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_headers' => function () {
                throw new \Exception('test');
            }
        ]);
        $promise->wait();
    }

    public function testSuccessfullyCallsOnHeadersBeforeWritingToSink()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123')
        ]);
        $req = new Request('GET', Server::$url);
        $got = null;

        $stream = Psr7\stream_for();
        $stream = FnStream::decorate($stream, [
            'write' => function ($data) use ($stream, &$got) {
                self::assertNotNull($got);
                return $stream->write($data);
            }
        ]);

        $handler = new StreamHandler();
        $promise = $handler($req, [
            'sink'       => $stream,
            'on_headers' => function (ResponseInterface $res) use (&$got) {
                $got = $res;
                self::assertSame('bar', $res->getHeaderLine('X-Foo'));
            }
        ]);

        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('abc 123', (string) $response->getBody());
    }

    public function testInvokesOnStatsOnSuccess()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200)]);
        $req = new Psr7\Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_stats' => function (TransferStats $stats) use (&$gotStats) {
                $gotStats = $stats;
            }
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200, $gotStats->getResponse()->getStatusCode());
        self::assertSame(
            Server::$url,
            (string) $gotStats->getEffectiveUri()
        );
        self::assertSame(
            Server::$url,
            (string) $gotStats->getRequest()->getUri()
        );
        self::assertGreaterThan(0, $gotStats->getTransferTime());
    }

    public function testInvokesOnStatsOnError()
    {
        $req = new Psr7\Request('GET', 'http://127.0.0.1:123');
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
            'on_stats' => function (TransferStats $stats) use (&$gotStats) {
                $gotStats = $stats;
            }
        ]);
        $promise->wait(false);
        self::assertFalse($gotStats->hasResponse());
        self::assertSame(
            'http://127.0.0.1:123',
            (string) $gotStats->getEffectiveUri()
        );
        self::assertSame(
            'http://127.0.0.1:123',
            (string) $gotStats->getRequest()->getUri()
        );
        self::assertInternalType('float', $gotStats->getTransferTime());
        self::assertInstanceOf(
            ConnectException::class,
            $gotStats->getHandlerErrorData()
        );
    }

    public function testStreamIgnoresZeroTimeout()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200)]);
        $req = new Psr7\Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 10,
            'timeout' => 0
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDrainsResponseAndReadsAllContentWhenContentLengthIsZero()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '0',
            ], 'hi there... This has a lot of data!')
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('hi there... This has a lot of data!', stream_get_contents($stream));
        fclose($stream);
    }

    public function testHonorsReadTimeout()
    {
        Server::flush();
        $handler = new StreamHandler();
        $response = $handler(
            new Request('GET', Server::$url . 'guzzle-server/read-timeout'),
            [
                RequestOptions::READ_TIMEOUT => 1,
                RequestOptions::STREAM => true,
            ]
        )->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        $body = $response->getBody()->detach();
        $line = fgets($body);
        self::assertSame("sleeping 60 seconds ...\n", $line);
        $line = fgets($body);
        self::assertFalse($line);
        self::assertTrue(stream_get_meta_data($body)['timed_out']);
        self::assertFalse(feof($body));
    }
}
