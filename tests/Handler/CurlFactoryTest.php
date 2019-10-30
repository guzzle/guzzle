<?php
namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\CurlFactory
 */
class CurlFactoryTest extends TestCase
{
    public static function setUpBeforeClass()
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl']);
    }

    public static function tearDownAfterClass()
    {
        unset($_SERVER['_curl'], $_SERVER['curl_test']);
    }

    public function testCreatesCurlHandle()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [
                'Foo' => 'Bar',
                'Baz' => 'bam',
                'Content-Length' => 2,
            ], 'hi')
        ]);
        $stream = Psr7\stream_for();
        $request = new Psr7\Request('PUT', Server::$url, [
            'Hi'             => ' 123',
            'Content-Length' => '7'
        ], 'testing');
        $f = new Handler\CurlFactory(3);
        $result = $f->create($request, ['sink' => $stream]);
        self::assertInstanceOf(EasyHandle::class, $result);
        self::assertInternalType('resource', $result->handle);
        self::assertInternalType('array', $result->headers);
        self::assertSame($stream, $result->sink);
        curl_close($result->handle);
        self::assertSame('PUT', $_SERVER['_curl'][CURLOPT_CUSTOMREQUEST]);
        self::assertSame(
            'http://127.0.0.1:8126/',
            $_SERVER['_curl'][CURLOPT_URL]
        );
        // Sends via post fields when the request is small enough
        self::assertSame('testing', $_SERVER['_curl'][CURLOPT_POSTFIELDS]);
        self::assertEquals(0, $_SERVER['_curl'][CURLOPT_RETURNTRANSFER]);
        self::assertEquals(0, $_SERVER['_curl'][CURLOPT_HEADER]);
        self::assertSame(150, $_SERVER['_curl'][CURLOPT_CONNECTTIMEOUT]);
        self::assertInstanceOf('Closure', $_SERVER['_curl'][CURLOPT_HEADERFUNCTION]);
        if (defined('CURLOPT_PROTOCOLS')) {
            self::assertSame(
                CURLPROTO_HTTP | CURLPROTO_HTTPS,
                $_SERVER['_curl'][CURLOPT_PROTOCOLS]
            );
        }
        self::assertContains('Expect:', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        self::assertContains('Accept:', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        self::assertContains('Content-Type:', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        self::assertContains('Hi: 123', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        self::assertContains('Host: 127.0.0.1:8126', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
    }

    public function testSendsHeadRequests()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), []);
        $response->wait();
        self::assertEquals(true, $_SERVER['_curl'][CURLOPT_NOBODY]);
        $checks = [CURLOPT_WRITEFUNCTION, CURLOPT_READFUNCTION, CURLOPT_INFILE];
        foreach ($checks as $check) {
            self::assertArrayNotHasKey($check, $_SERVER['_curl']);
        }
        self::assertEquals('HEAD', Server::received()[0]->getMethod());
    }

    public function testCanAddCustomCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);
        $a($req, ['curl' => [CURLOPT_LOW_SPEED_LIMIT => 10]]);
        self::assertEquals(10, $_SERVER['_curl'][CURLOPT_LOW_SPEED_LIMIT]);
    }

    public function testCanChangeCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);
        $a($req, ['curl' => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0]]);
        self::assertEquals(CURL_HTTP_VERSION_1_0, $_SERVER['_curl'][CURLOPT_HTTP_VERSION]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage SSL CA bundle not found: /does/not/exist
     */
    public function testValidatesVerify()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => '/does/not/exist']);
    }

    public function testCanSetVerifyToFile()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_CAINFO]);
        self::assertEquals(2, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        self::assertEquals(true, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
    }

    public function testCanSetVerifyToDir()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __DIR__]);
        self::assertEquals(__DIR__, $_SERVER['_curl'][CURLOPT_CAPATH]);
        self::assertEquals(2, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        self::assertEquals(true, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsVerifyAsTrue()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => true]);
        self::assertEquals(2, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        self::assertEquals(true, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
        self::assertArrayNotHasKey(CURLOPT_CAINFO, $_SERVER['_curl']);
    }

    public function testCanDisableVerify()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => false]);
        self::assertEquals(0, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        self::assertEquals(false, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsProxy()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['proxy' => 'http://bar.com']);
        self::assertEquals('http://bar.com', $_SERVER['_curl'][CURLOPT_PROXY]);
    }

    public function testAddsViaScheme()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'proxy' => ['http' => 'http://bar.com', 'https' => 'https://t'],
        ]);
        self::assertEquals('http://bar.com', $_SERVER['_curl'][CURLOPT_PROXY]);
        $this->checkNoProxyForHost('http://test.test.com', ['test.test.com'], false);
        $this->checkNoProxyForHost('http://test.test.com', ['.test.com'], false);
        $this->checkNoProxyForHost('http://test.test.com', ['*.test.com'], true);
        $this->checkNoProxyForHost('http://test.test.com', ['*'], false);
        $this->checkNoProxyForHost('http://127.0.0.1', ['127.0.0.*'], true);
    }

    private function checkNoProxyForHost($url, $noProxy, $assertUseProxy)
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', $url), [
            'proxy' => [
                'http' => 'http://bar.com',
                'https' => 'https://t',
                'no' => $noProxy
            ],
        ]);
        if ($assertUseProxy) {
            self::assertArrayHasKey(CURLOPT_PROXY, $_SERVER['_curl']);
        } else {
            self::assertArrayNotHasKey(CURLOPT_PROXY, $_SERVER['_curl']);
        }
    }


    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage SSL private key not found: /does/not/exist
     */
    public function testValidatesSslKey()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => '/does/not/exist']);
    }

    public function testAddsSslKey()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLKEY]);
    }

    public function testAddsSslKeyWithPassword()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__, 'test']]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLKEY]);
        self::assertEquals('test', $_SERVER['_curl'][CURLOPT_SSLKEYPASSWD]);
    }

    public function testAddsSslKeyWhenUsingArraySyntaxButNoPassword()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__]]);

        self::assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLKEY]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage SSL certificate not found: /does/not/exist
     */
    public function testValidatesCert()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => '/does/not/exist']);
    }

    public function testAddsCert()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLCERT]);
    }

    public function testAddsCertWithPassword()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => [__FILE__, 'test']]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLCERT]);
        self::assertEquals('test', $_SERVER['_curl'][CURLOPT_SSLCERTPASSWD]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage progress client option must be callable
     */
    public function testValidatesProgress()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['progress' => 'foo']);
    }

    public function testEmitsDebugInfoToStream()
    {
        $res = fopen('php://memory', 'r+');
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), ['debug' => $res]);
        $response->wait();
        rewind($res);
        $output = str_replace("\r", '', stream_get_contents($res));
        self::assertContains("> HEAD / HTTP/1.1", $output);
        self::assertContains("< HTTP/1.1 200", $output);
        fclose($res);
    }

    public function testEmitsProgressToFunction()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $called = [];
        $request = new Psr7\Request('HEAD', Server::$url);
        $response = $a($request, [
            'progress' => function () use (&$called) {
                $called[] = func_get_args();
            },
        ]);
        $response->wait();
        self::assertNotEmpty($called);
        foreach ($called as $call) {
            self::assertCount(4, $call);
        }
    }

    private function addDecodeResponse($withEncoding = true)
    {
        $content = gzencode('test');
        $headers = ['Content-Length' => strlen($content)];
        if ($withEncoding) {
            $headers['Content-Encoding'] = 'gzip';
        }
        $response  = new Psr7\Response(200, $headers, $content);
        Server::flush();
        Server::enqueue([$response]);
        return $content;
    }

    public function testDecodesGzippedResponses()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        self::assertEquals('test', (string) $response->getBody());
        self::assertEquals('', $_SERVER['_curl'][CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        self::assertFalse($sent->hasHeader('Accept-Encoding'));
    }

    public function testReportsOriginalSizeAndContentEncodingAfterDecoding()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        self::assertSame(
            'gzip',
            $response->getHeaderLine('x-encoded-content-encoding')
        );
        self::assertSame(
            strlen(gzencode('test')),
            (int) $response->getHeaderLine('x-encoded-content-length')
        );
    }

    public function testDecodesGzippedResponsesWithHeader()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, ['Accept-Encoding' => 'gzip']);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        self::assertEquals('gzip', $_SERVER['_curl'][CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        self::assertEquals('gzip', $sent->getHeaderLine('Accept-Encoding'));
        self::assertEquals('test', (string) $response->getBody());
        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertTrue(
            !$response->hasHeader('content-length') ||
            $response->getHeaderLine('content-length') == $response->getBody()->getSize()
        );
    }

    public function testDoesNotForceDecode()
    {
        $content = $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => false]);
        $response = $response->wait();
        $sent = Server::received()[0];
        self::assertFalse($sent->hasHeader('Accept-Encoding'));
        self::assertEquals($content, (string) $response->getBody());
    }

    public function testProtocolVersion()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, [], null, '1.0');
        $a($request, []);
        self::assertEquals(CURL_HTTP_VERSION_1_0, $_SERVER['_curl'][CURLOPT_HTTP_VERSION]);
    }

    public function testSavesToStream()
    {
        $stream = fopen('php://memory', 'r+');
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink'           => $stream,
        ]);
        $response->wait();
        rewind($stream);
        self::assertEquals('test', stream_get_contents($stream));
    }

    public function testSavesToGuzzleStream()
    {
        $stream = Psr7\stream_for();
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink'           => $stream,
        ]);
        $response->wait();
        self::assertEquals('test', (string) $stream);
    }

    public function testSavesToFileOnDisk()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'testfile');
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink'           => $tmpfile,
        ]);
        $response->wait();
        self::assertStringEqualsFile($tmpfile, 'test');
        unlink($tmpfile);
    }

    public function testDoesNotAddMultipleContentLengthHeaders()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('PUT', Server::$url, ['Content-Length' => 3], 'foo');
        $response = $handler($request, []);
        $response->wait();
        $sent = Server::received()[0];
        self::assertEquals(3, $sent->getHeaderLine('Content-Length'));
        self::assertFalse($sent->hasHeader('Transfer-Encoding'));
        self::assertEquals('foo', (string) $sent->getBody());
    }

    public function testSendsPostWithNoBodyOrDefaultContentType()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('POST', Server::$url);
        $response = $handler($request, []);
        $response->wait();
        $received = Server::received()[0];
        self::assertEquals('POST', $received->getMethod());
        self::assertFalse($received->hasHeader('content-type'));
        self::assertSame('0', $received->getHeaderLine('content-length'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage but attempting to rewind the request body failed
     */
    public function testFailsWhenCannotRewindRetryAfterNoResponse()
    {
        $factory = new Handler\CurlFactory(1);
        $stream = Psr7\stream_for('abc');
        $stream->read(1);
        $stream = new Psr7\NoSeekStream($stream);
        $request = new Psr7\Request('PUT', Server::$url, [], $stream);
        $fn = function ($request, $options) use (&$fn, $factory) {
            $easy = $factory->create($request, $options);
            return Handler\CurlFactory::finish($fn, $easy, $factory);
        };
        $fn($request, [])->wait();
    }

    public function testRetriesWhenBodyCanBeRewound()
    {
        $callHandler = $called = false;

        $fn = function ($r, $options) use (&$callHandler) {
            $callHandler = true;
            return \GuzzleHttp\Promise\promise_for(new Psr7\Response());
        };

        $bd = Psr7\FnStream::decorate(Psr7\stream_for('test'), [
            'tell'   => function () {
                return 1;
            },
            'rewind' => function () use (&$called) {
                $called = true;
            }
        ]);

        $factory = new Handler\CurlFactory(1);
        $req = new Psr7\Request('PUT', Server::$url, [], $bd);
        $easy = $factory->create($req, []);
        $res = Handler\CurlFactory::finish($fn, $easy, $factory);
        $res = $res->wait();
        self::assertTrue($callHandler);
        self::assertTrue($called);
        self::assertEquals('200', $res->getStatusCode());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage The cURL request was retried 3 times
     */
    public function testFailsWhenRetryMoreThanThreeTimes()
    {
        $factory = new Handler\CurlFactory(1);
        $call = 0;
        $fn = function ($request, $options) use (&$mock, &$call, $factory) {
            $call++;
            $easy = $factory->create($request, $options);
            return Handler\CurlFactory::finish($mock, $easy, $factory);
        };
        $mock = new Handler\MockHandler([$fn, $fn, $fn]);
        $p = $mock(new Psr7\Request('PUT', Server::$url, [], 'test'), []);
        $p->wait(false);
        self::assertEquals(3, $call);
        $p->wait(true);
    }

    public function testHandles100Continue()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['Test' => 'Hello', 'Content-Length' => 4], 'test'),
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [
            'Expect' => '100-Continue'
        ], 'test');
        $handler = new Handler\CurlMultiHandler();
        $response = $handler($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('Hello', $response->getHeaderLine('Test'));
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
        self::assertSame('test', (string) $response->getBody());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ConnectException
     */
    public function testCreatesConnectException()
    {
        $m = new \ReflectionMethod(CurlFactory::class, 'finishError');
        $m->setAccessible(true);
        $factory = new Handler\CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);
        $easy->errno = CURLE_COULDNT_CONNECT;
        $response = $m->invoke(
            null,
            function () {
            },
            $easy,
            $factory
        );
        $response->wait();
    }

    public function testAddsTimeouts()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'timeout'         => 0.1,
            'connect_timeout' => 0.2
        ]);
        self::assertEquals(100, $_SERVER['_curl'][CURLOPT_TIMEOUT_MS]);
        self::assertEquals(200, $_SERVER['_curl'][CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testAddsStreamingBody()
    {
        $f = new Handler\CurlFactory(3);
        $bd = Psr7\FnStream::decorate(Psr7\stream_for('foo'), [
            'getSize' => function () {
                return null;
            }
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $bd);
        $f->create($request, []);
        self::assertEquals(1, $_SERVER['_curl'][CURLOPT_UPLOAD]);
        self::assertInternalType('callable', $_SERVER['_curl'][CURLOPT_READFUNCTION]);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Directory /does/not/exist/so does not exist for sink value of /does/not/exist/so/error.txt
     */
    public function testEnsuresDirExistsBeforeThrowingWarning()
    {
        $f = new Handler\CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'sink' => '/does/not/exist/so/error.txt'
        ]);
    }

    public function testClosesIdleHandles()
    {
        $f = new Handler\CurlFactory(3);
        $req = new Psr7\Request('GET', Server::$url);
        $easy = $f->create($req, []);
        $h1 = $easy->handle;
        $f->release($easy);
        self::assertCount(1, self::readAttribute($f, 'handles'));
        $easy = $f->create($req, []);
        self::assertSame($easy->handle, $h1);
        $easy2 = $f->create($req, []);
        $easy3 = $f->create($req, []);
        $easy4 = $f->create($req, []);
        $f->release($easy);
        self::assertCount(1, self::readAttribute($f, 'handles'));
        $f->release($easy2);
        self::assertCount(2, self::readAttribute($f, 'handles'));
        $f->release($easy3);
        self::assertCount(3, self::readAttribute($f, 'handles'));
        $f->release($easy4);
        self::assertCount(3, self::readAttribute($f, 'handles'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresOnHeadersIsCallable()
    {
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
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
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123')
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
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
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123')
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $got = null;

        $stream = Psr7\stream_for();
        $stream = Psr7\FnStream::decorate($stream, [
            'write' => function ($data) use ($stream, &$got) {
                self::assertNotNull($got);
                return $stream->write($data);
            }
        ]);

        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'sink'       => $stream,
            'on_headers' => function (ResponseInterface $res) use (&$got) {
                $got = $res;
                self::assertEquals('bar', $res->getHeaderLine('X-Foo'));
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
        $handler = new Handler\CurlHandler();
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
        self::assertArrayHasKey('appconnect_time', $gotStats->getHandlerStats());
    }

    public function testInvokesOnStatsOnError()
    {
        $req = new Psr7\Request('GET', 'http://127.0.0.1:123');
        $gotStats = null;
        $handler = new Handler\CurlHandler();
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
        self::assertInternalType('int', $gotStats->getHandlerErrorData());
        self::assertArrayHasKey('appconnect_time', $gotStats->getHandlerStats());
    }

    public function testRewindsBodyIfPossible()
    {
        $body = Psr7\stream_for(str_repeat('x', 1024 * 1024 * 2));
        $body->seek(1024 * 1024);
        self::assertSame(1024 * 1024, $body->tell());

        $req = new Psr7\Request('POST', 'https://www.example.com', [
            'Content-Length' => 1024 * 1024 * 2,
        ], $body);
        $factory = new CurlFactory(1);
        $factory->create($req, []);

        self::assertSame(0, $body->tell());
    }

    public function testDoesNotRewindUnseekableBody()
    {
        $body = Psr7\stream_for(str_repeat('x', 1024 * 1024 * 2));
        $body->seek(1024 * 1024);
        $body = new Psr7\NoSeekStream($body);
        self::assertSame(1024 * 1024, $body->tell());

        $req = new Psr7\Request('POST', 'https://www.example.com', [
            'Content-Length' => 1024 * 1024,
        ], $body);
        $factory = new CurlFactory(1);
        $factory->create($req, []);

        self::assertSame(1024 * 1024, $body->tell());
    }

    public function testRelease()
    {
        $factory = new CurlFactory(1);
        $easyHandle = new EasyHandle();
        $easyHandle->handle = curl_init();

        self::assertEmpty($factory->release($easyHandle));
    }
}
