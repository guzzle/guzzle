<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
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
                'Content-Length' => '8',
            ], 'hi there'),
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
        self::assertSame('127.0.0.1', $sent->getUri()->getHost());
        self::assertSame(8126, $sent->getUri()->getPort());
        self::assertSame('/', $sent->getUri()->getPath());
        self::assertSame('127.0.0.1:8126', $sent->getHeaderLine('Host'));
        self::assertSame('Bar', $sent->getHeaderLine('foo'));
    }

    public function testEmptyProtocolVersionDefaultsToHttp11()
    {
        $this->queueRes();
        $handler = new StreamHandler();

        $response = $handler(new Request('GET', Server::$url, [], null, ''), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1.1', Server::received()[0]->getProtocolVersion());
    }

    public function testAddsErrorToResponse()
    {
        $handler = new StreamHandler();

        $this->expectException(ConnectException::class);
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
            Server::$url.'foo?baz=bar',
            ['Foo' => 'Bar'],
            'test'
        );
        $response = $handler($request, ['stream' => true])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertIsResource($stream);
        self::assertSame('http', \stream_get_meta_data($stream)['wrapper_type']);
        self::assertSame('hi there', \stream_get_contents($stream));
        \fclose($stream);
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
        self::assertSame('php://temp', \stream_get_meta_data($stream)['uri']);
        self::assertSame('hi', \fread($stream, 2));
        \fclose($stream);
    }

    public function testDrainsResponseIntoSaveToBody()
    {
        $r = \fopen('php://temp', 'r+');
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['sink' => $r])->wait();
        $body = $response->getBody()->detach();
        self::assertSame('php://temp', \stream_get_meta_data($body)['uri']);
        self::assertSame('hi', \fread($body, 2));
        self::assertSame(' there', \stream_get_contents($r));
        \fclose($r);
    }

    public function testDrainsResponseIntoSaveToBodyAtPath()
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'save_to_path');
        $body = null;

        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $request = new Request('GET', Server::$url);
            $response = $handler($request, ['sink' => $tmpfname])->wait();
            $body = $response->getBody();
            self::assertSame($tmpfname, $body->getMetadata('uri'));
            self::assertSame('hi', $body->read(2));
        } finally {
            if ($body !== null) {
                $body->close();
            }
            if (\file_exists($tmpfname)) {
                \unlink($tmpfname);
            }
        }
    }

    public function testDrainsResponseIntoSaveToBodyAtNonExistentPath()
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'save_to_path');
        \unlink($tmpfname);
        $body = null;

        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $request = new Request('GET', Server::$url);
            $response = $handler($request, ['sink' => $tmpfname])->wait();
            $body = $response->getBody();
            self::assertSame($tmpfname, $body->getMetadata('uri'));
            self::assertSame('hi', $body->read(2));
        } finally {
            if ($body !== null) {
                $body->close();
            }
            if (\file_exists($tmpfname)) {
                \unlink($tmpfname);
            }
        }
    }

    public function testDrainsResponseAndReadsOnlyContentLengthBytes()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '8',
            ], 'hi there... This has way too much data!'),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('hi there', \stream_get_contents($stream));
        \fclose($stream);
    }

    public function testDoesNotDrainWhenHeadRequest()
    {
        Server::flush();
        // Say the content-length is 8, but return no response.
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '8',
            ], ''),
        ]);
        $handler = new StreamHandler();
        $request = new Request('HEAD', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('', \stream_get_contents($stream));
        \fclose($stream);
    }

    public function testAutomaticallyDecompressGzip()
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();
        self::assertSame('test', (string) $response->getBody());
        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertTrue(!$response->hasHeader('content-length') || $response->getHeaderLine('content-length') == $response->getBody()->getSize());
    }

    public function testDecodedGzipLargerThanEncodedReturnsFullBodyAndDropsContentLength()
    {
        $decoded = \str_repeat('A', 1000);
        $gzip = \gzencode($decoded);
        self::assertIsString($gzip);

        $resource = \fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        \fwrite($resource, $gzip);
        \rewind($resource);

        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');

        $ref = new \ReflectionObject($handler);
        $lastHeaders = $ref->getProperty('lastHeaders');
        if (\PHP_VERSION_ID < 80100) {
            $lastHeaders->setAccessible(true);
        }
        $lastHeaders->setValue($handler, [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: '.\strlen($gzip),
        ]);
        $createResponse = $ref->getMethod('createResponse');
        if (\PHP_VERSION_ID < 80100) {
            $createResponse->setAccessible(true);
        }

        /** @var ResponseInterface $response */
        $response = $createResponse->invoke($handler, $request, ['decode_content' => true], $resource, null)->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($decoded, (string) $response->getBody());
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame((string) \strlen($gzip), $response->getHeaderLine('x-encoded-content-length'));
    }

    public function testAutomaticallyDecompressGzipHead()
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('HEAD', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();

        // Verify that the content-length is removed after decoding.
        self::assertTrue(!$response->hasHeader('content-length') || $response->getHeaderLine('content-length') == \strlen($content));
    }

    public function testReportsOriginalSizeAndContentEncodingAfterDecoding()
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();

        self::assertSame(
            'gzip',
            $response->getHeaderLine('x-encoded-content-encoding')
        );
        self::assertSame(
            \strlen($content),
            (int) $response->getHeaderLine('x-encoded-content-length')
        );
    }

    public function testDoesNotForceGzipDecode()
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => false])->wait();
        self::assertSame($content, (string) $response->getBody());
        self::assertSame('gzip', $response->getHeaderLine('content-encoding'));
        self::assertEquals(\strlen($content), $response->getHeaderLine('content-length'));
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

    public function testAddsProxy()
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->getSendResult(['proxy' => '127.0.0.1:8125']);
    }

    public function testAddsProxyByProtocol()
    {
        $url = Server::$url;
        $res = $this->getSendResult(['proxy' => ['http' => $url]]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        foreach ([\PHP_URL_HOST, \PHP_URL_PORT] as $part) {
            self::assertSame(parse_url($url, $part), parse_url($opts['http']['proxy'], $part));
        }
    }

    public function testAddsProxyButHonorsNoProxy()
    {
        $url = Server::$url;
        $res = $this->getSendResult(['proxy' => [
            'http' => $url,
            'no' => ['*'],
        ]]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('proxy', $opts['http']);
    }

    public function testAddsProxyButHonorsNoProxyPorts()
    {
        $proxy = [
            'http' => 'http://proxy.example.com:8125',
            'https' => 'http://proxy.example.com:8125',
            'no' => ['example.com:80'],
        ];

        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://example.com')['http']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'https://example.com')['http']['proxy']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://example.com:8080')['http']['proxy']);

        $proxy['no'] = ['.example.com:8080'];
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://foo.example.com:8080')['http']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://example.com:8080')['http']['proxy']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://foo.example.com:8081')['http']['proxy']);

        $proxy['no'] = ['[::1]:8080'];
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://[::1]:8080')['http']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://[::1]:8081')['http']['proxy']);
    }

    public function testAddsProxyButHonorsStringNoProxy()
    {
        $proxy = [
            'http' => 'http://proxy.example.com:8125',
            'https' => 'http://proxy.example.com:8125',
            'no' => 'example.com, foo.example.com',
        ];

        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://example.com')['http']);
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://foo.example.com')['http']);

        $proxy['no'] = ' example.com:80 , [::1]:8080 ';
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://example.com')['http']);
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://[::1]:8080')['http']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'https://example.com')['http']['proxy']);

        $proxy['no'] = '';
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://example.com')['http']['proxy']);

        $proxy['no'] = null;
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://example.com')['http']['proxy']);
    }

    public function testUsesProxy()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://www.example.com', [], null, '1.0');
        $response = $handler($request, [
            'proxy' => Server::$url,
        ])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        self::assertSame('hi there', (string) $response->getBody());
    }

    private function getProxyContext($proxy, $uri = 'http://example.com')
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $options = ['http' => []];
        $params = [];
        $method = new \ReflectionMethod(StreamHandler::class, 'add_proxy');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [$request, &$options, $proxy, &$params]);

        return $options;
    }

    public function testAddsTimeout()
    {
        $res = $this->getSendResult(['stream' => true, 'timeout' => 200]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertEquals(200, $opts['http']['timeout']);
    }

    public function testVerifiesVerifyIsValidIfPath()
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('SSL CA bundle not found: /does/not/exist');

        $this->getSendResult(['verify' => '/does/not/exist']);
    }

    public function testVerifyCanBeDisabled()
    {
        $handler = $this->getSendResult(['verify' => false]);
        self::assertInstanceOf(Response::class, $handler);
    }

    public function testVerifiesCertIfValidPath()
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('SSL certificate not found: /does/not/exist');

        $this->getSendResult(['cert' => '/does/not/exist']);
    }

    public function testVerifyCanBeSetToPath()
    {
        $path = Utils::defaultCaBundle();
        $res = $this->getSendResult(['verify' => $path]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertTrue($opts['ssl']['verify_peer']);
        self::assertTrue($opts['ssl']['verify_peer_name']);
        self::assertSame($path, $opts['ssl']['cafile']);
        self::assertFileExists($opts['ssl']['cafile']);
    }

    public function testUsesSystemDefaultBundle()
    {
        $res = $this->getSendResult(['verify' => true]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('cafile', $opts['ssl']);
    }

    public function testEnsuresVerifyOptionIsValid()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid verify request option');

        $this->getSendResult(['verify' => 10]);
    }

    public function testEnsuresCryptoMethodOptionIsValid()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method request option: unknown version provided');

        $this->getSendResult(['crypto_method' => 123]);
    }

    public function testSetsCryptoMethodTls10()
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT, $opts['http']['crypto_method']);
    }

    public function testSetsCryptoMethodTls11()
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT, $opts['http']['crypto_method']);
    }

    public function testSetsCryptoMethodTls12()
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $opts['http']['crypto_method']);
    }

    /**
     * @requires PHP >=7.4
     */
    public function testSetsCryptoMethodTls13()
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, $opts['http']['crypto_method']);
    }

    public function testCanSetPasswordWhenSettingCert()
    {
        $path = __FILE__;
        $res = $this->getSendResult(['cert' => [$path, 'foo']]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testCanSetCertWithArrayPathOnly()
    {
        $path = __FILE__;
        $handler = new StreamHandler();
        $options = [];
        $params = [];
        $method = new \ReflectionMethod(StreamHandler::class, 'add_cert');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [new Request('GET', 'http://example.com'), &$options, [$path], &$params]);

        self::assertSame($path, $options['ssl']['local_cert']);
        self::assertArrayNotHasKey('passphrase', $options['ssl']);
    }

    public function testCanSetCertTypeToPem()
    {
        $response = $this->getSendResult(['cert_type' => 'pem']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsNonPemCertType()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream handler only supports "PEM" for the cert_type request option.');

        $this->getSendResult(['cert_type' => 'DER']);
    }

    public function testCanSetSslKey()
    {
        $path = __FILE__;
        $res = $this->getSendResult(['ssl_key' => $path]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_pk']);
    }

    public function testCanSetPasswordWhenSettingSslKey()
    {
        $path = __FILE__;
        $res = $this->getSendResult(['ssl_key' => [$path, 'foo']]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_pk']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testCanSetCertAndSslKeyWithSamePassword()
    {
        $path = __FILE__;
        $res = $this->getSendResult([
            'cert' => [$path, 'foo'],
            'ssl_key' => [$path, 'foo'],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertSame($path, $opts['ssl']['local_pk']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testRejectsCertAndSslKeyWithDifferentPasswords()
    {
        $path = __FILE__;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot use different passphrases for cert and ssl_key with the stream handler');

        $this->getSendResult([
            'cert' => [$path, 'foo'],
            'ssl_key' => [$path, 'bar'],
        ]);
    }

    public function testCanSetSslKeyWithArrayPathOnly()
    {
        $path = __FILE__;
        $handler = new StreamHandler();
        $options = [];
        $params = [];
        $method = new \ReflectionMethod(StreamHandler::class, 'add_ssl_key');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [new Request('GET', 'http://example.com'), &$options, [$path], &$params]);

        self::assertSame($path, $options['ssl']['local_pk']);
        self::assertArrayNotHasKey('passphrase', $options['ssl']);
    }

    public function testCanSetSslKeyTypeToPem()
    {
        $response = $this->getSendResult(['ssl_key_type' => 'pem']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsNonPemSslKeyType()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream handler only supports "PEM" for the ssl_key_type request option.');

        $this->getSendResult(['ssl_key_type' => 'DER']);
    }

    /**
     * @dataProvider invalidCertOptionProvider
     *
     * @param mixed $cert
     */
    public function testEnsuresCertOptionShapeIsValid($cert)
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cert request option');
        $handler(new Request('GET', 'http://example.com'), ['cert' => $cert]);
    }

    public static function invalidCertOptionProvider(): array
    {
        return [
            [[]],
            [['passphrase' => 'test']],
            [[new \stdClass(), 'test']],
            [[__FILE__, new \stdClass()]],
            [new \stdClass()],
        ];
    }

    /**
     * @dataProvider invalidSslKeyOptionProvider
     *
     * @param mixed $sslKey
     */
    public function testEnsuresSslKeyOptionShapeIsValid($sslKey)
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ssl_key request option');
        $handler(new Request('GET', 'http://example.com'), ['ssl_key' => $sslKey]);
    }

    public static function invalidSslKeyOptionProvider(): array
    {
        return [
            [[]],
            [['passphrase' => 'test']],
            [[new \stdClass(), 'test']],
            [[__FILE__, new \stdClass()]],
            [new \stdClass()],
        ];
    }

    public function testDebugAttributeWritesToStream()
    {
        $this->queueRes();
        $f = \fopen('php://temp', 'w+');
        $this->getSendResult(['debug' => $f]);
        \fseek($f, 0);
        $contents = \stream_get_contents($f);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [CONNECT]', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [FILE_SIZE_IS]', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [PROGRESS]', $contents);
    }

    public function testDebugAttributeWritesStreamInfoToBuffer()
    {
        $called = false;
        $this->queueRes();
        $buffer = \fopen('php://temp', 'r+');
        $this->getSendResult([
            'progress' => static function () use (&$called) {
                $called = true;
            },
            'debug' => $buffer,
        ]);
        \fseek($buffer, 0);
        $contents = \stream_get_contents($buffer);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [CONNECT]', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [FILE_SIZE_IS] message: "Content-Length: 8"', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [PROGRESS] bytes_max: "8"', $contents);
        self::assertTrue($called);
    }

    public function testEmitsProgressInformation()
    {
        $called = [];
        $this->queueRes();
        $this->getSendResult([
            'progress' => static function (...$args) use (&$called) {
                $called[] = $args;
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
        $buffer = \fopen('php://memory', 'w+');
        $this->getSendResult([
            'debug' => $buffer,
            'progress' => static function (...$args) use (&$called) {
                $called[] = $args;
            },
        ]);
        self::assertNotEmpty($called);
        self::assertEquals(8, $called[0][0]);
        self::assertEquals(0, $called[0][1]);
        \rewind($buffer);
        self::assertNotEmpty(\stream_get_contents($buffer));
        \fclose($buffer);
    }

    public function testPerformsShallowMergeOfCustomContextOptions()
    {
        $res = $this->getSendResult([
            'stream_context' => [
                'http' => [
                    'request_fulluri' => true,
                ],
                'socket' => [
                    'bindto' => '127.0.0.1:0',
                ],
                'ssl' => [
                    'ciphers' => 'DEFAULT',
                ],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertTrue($opts['http']['request_fulluri']);
        self::assertSame('127.0.0.1:0', $opts['socket']['bindto']);
        self::assertSame('DEFAULT', $opts['ssl']['ciphers']);
    }

    public function testEnsuresThatStreamContextIsAnArray()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_context must be an array');

        $this->getSendResult(['stream_context' => 'foo']);
    }

    public function testDoesNotAddContentTypeByDefault()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, ['Content-Length' => '3'], 'foo');
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

    public function testAddsContentLengthForPUTEvenWhenEmpty()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthForPOSTEvenWhenEmpty()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('POST', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testDontAddContentLengthForGETEvenWhenEmpty()
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertSame('', $req->getHeaderLine('Content-Length'));
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
        $response = new Response(200);
        Server::enqueue([$response]);
        $a = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $s = Utils::currentTime();
        $a($request, ['delay' => 0.1])->wait();
        self::assertGreaterThan(0.0001, Utils::currentTime() - $s);
    }

    public function testEnsuresOnHeadersIsCallable()
    {
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $handler($req, ['on_headers' => 'error!']);
    }

    public function testEnsuresProgressIsCallable()
    {
        $req = new Request('GET', 'http://example.com');
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('progress client option must be callable');
        $handler($req, ['progress' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_headers' => static function () {
                throw new \Exception('test');
            },
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An error was encountered during the on_headers event');
        $promise->wait();
    }

    public function testRejectsPromiseWhenOnHeadersThrowsThrowable()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_headers' => static function (): void {
                throw new \Error('test');
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered during the on_headers event',
                $e->getMessage()
            );
            self::assertInstanceOf(\Error::class, $e->getPrevious());
        }
    }

    public function testSuccessfullyCallsOnHeadersBeforeWritingToSink()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $got = null;

        $stream = Psr7\Utils::streamFor();
        $stream = FnStream::decorate($stream, [
            'write' => static function ($data) use ($stream, &$got) {
                self::assertNotNull($got);

                return $stream->write($data);
            },
        ]);

        $handler = new StreamHandler();
        $promise = $handler($req, [
            'sink' => $stream,
            'on_headers' => static function (ResponseInterface $res) use (&$got) {
                $got = $res;
                self::assertSame('bar', $res->getHeaderLine('X-Foo'));
            },
        ]);

        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('abc 123', (string) $response->getBody());
    }

    public function testInvokesOnStatsOnSuccess()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_stats' => static function (TransferStats $stats) use (&$gotStats) {
                $gotStats = $stats;
            },
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
        $req = new Request('GET', 'http://127.0.0.1:123');
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
            'on_stats' => static function (TransferStats $stats) use (&$gotStats) {
                $gotStats = $stats;
            },
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
        self::assertIsFloat($gotStats->getTransferTime());
        self::assertInstanceOf(
            ConnectException::class,
            $gotStats->getHandlerErrorData()
        );
    }

    public function testStreamIgnoresZeroTimeout()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 10,
            'timeout' => 0,
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsDisabledTransportSharingConstructorOption()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler(['transport_sharing' => TransportSharing::NONE]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsNullTransportSharingConstructorOption()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler(['transport_sharing' => null]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsPreferredTransportSharingConstructorOption()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler(['transport_sharing' => TransportSharing::HANDLER_PREFER]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamRejectsRequiredTransportSharingConstructorOption()
    {
        $handler = new StreamHandler(['transport_sharing' => TransportSharing::HANDLER_REQUIRE]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport_sharing');

        $handler(new Request('GET', Server::$url), []);
    }

    /**
     * @dataProvider requestTransportSharingOptionProvider
     *
     * @param mixed $transportSharing
     */
    public function testStreamIgnoresRequestLevelTransportSharingOption($transportSharing)
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [
            'transport_sharing' => $transportSharing,
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function requestTransportSharingOptionProvider(): iterable
    {
        yield 'null' => [null];
        yield 'none' => [TransportSharing::NONE];
        yield 'handler prefer' => [TransportSharing::HANDLER_PREFER];
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'invalid' => ['invalid'];
    }

    public function testDrainsResponseAndReadsAllContentWhenContentLengthIsZero()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '0',
            ], 'hi there... This has a lot of data!'),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('hi there... This has a lot of data!', \stream_get_contents($stream));
        \fclose($stream);
    }

    public function testHonorsReadTimeout()
    {
        Server::flush();
        $handler = new StreamHandler();
        $response = $handler(
            new Request('GET', Server::$url.'guzzle-server/read-timeout'),
            [
                RequestOptions::READ_TIMEOUT => 1,
                RequestOptions::STREAM => true,
            ]
        )->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        $body = $response->getBody()->detach();
        $line = \fgets($body);
        self::assertSame("sleeping 60 seconds ...\n", $line);
        $line = \fgets($body);
        self::assertFalse($line);
        self::assertTrue(\stream_get_meta_data($body)['timed_out']);
        self::assertFalse(\feof($body));
    }

    public function testHandlesGarbageHttpServerGracefully()
    {
        $handler = new StreamHandler();

        try {
            $handler(
                new Request('GET', Server::$url.'guzzle-server/garbage'),
                [
                    RequestOptions::STREAM => true,
                ]
            )->wait();
            self::fail('Expected an exception');
        } catch (ConnectException $e) {
            self::assertStringContainsString('Connection refused', $e->getMessage());
        } catch (RequestException $e) {
            self::assertStringContainsString('An error was encountered while creating the response', $e->getMessage());
        }
    }

    public function testHandlesInvalidStatusCodeGracefully()
    {
        $handler = new StreamHandler();
        $called = false;
        $stats = null;

        try {
            $handler(
                new Request('GET', Server::$url.'guzzle-server/bad-status'),
                [
                    RequestOptions::STREAM => true,
                    'on_headers' => static function () use (&$called): void {
                        $called = true;
                    },
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ]
            )->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered while creating the response',
                $e->getMessage()
            );
            self::assertFalse($called);
            self::assertFalse($e->hasResponse());
            self::assertNull($e->getResponse());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertFalse($stats->hasResponse());
            self::assertNull($stats->getResponse());
            self::assertSame($e, $stats->getHandlerErrorData());
        }
    }

    public function testRejectsNonHttpSchemes()
    {
        $handler = new StreamHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("The scheme 'file' is not supported.");

        $handler(
            new Request('GET', 'file:///etc/passwd'),
            [
                RequestOptions::STREAM => true,
            ]
        )->wait();
    }

    public function testProtocolsOptionRejectsDisallowedStreamScheme()
    {
        $handler = new StreamHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('not allowed by the protocols request option');

        $handler(
            new Request('GET', Server::$url),
            [
                RequestOptions::STREAM => true,
                RequestOptions::PROTOCOLS => ['https'],
            ]
        )->wait();
    }

    private function parseProxyResult($url)
    {
        $method = new \ReflectionMethod(StreamHandler::class, 'parse_proxy');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invokeArgs(new StreamHandler(), [$url]);
    }

    public function proxyParseProvider()
    {
        return [
            'scheme-less host' => [
                'proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => null],
            ],
            'scheme-less credentials' => [
                'user:pass@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'scheme-less username only' => [
                'user@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:')],
            ],
            'scheme-less empty password' => [
                'user:@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:')],
            ],
            'scheme-less empty userinfo' => [
                '@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => null],
            ],
            'scheme-less ipv6' => [
                '[::1]:8125',
                ['proxy' => 'tcp://[::1]:8125', 'auth' => null],
            ],
            'scheme-less ipv6 credentials' => [
                'user:pass@[::1]:8125',
                ['proxy' => 'tcp://[::1]:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'explicit http credentials' => [
                'http://user:pass@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'uppercase http scheme' => [
                'HTTP://user:pass@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'raw transport unchanged' => [
                'ssl://proxy.example.com:8125',
                ['proxy' => 'ssl://proxy.example.com:8125', 'auth' => null],
            ],
            'malformed socks-like unchanged' => [
                'socks5:127.0.0.1:1080',
                ['proxy' => 'socks5:127.0.0.1:1080', 'auth' => null],
            ],
            'malformed http-like unchanged' => [
                'http:127.0.0.1:8125',
                ['proxy' => 'http:127.0.0.1:8125', 'auth' => null],
            ],
            'protocol-relative unchanged' => [
                '//proxy.example.com:8125',
                ['proxy' => '//proxy.example.com:8125', 'auth' => null],
            ],
        ];
    }

    /**
     * @dataProvider proxyParseProvider
     */
    public function testTranslatesProxyForStreamContext($url, $expected)
    {
        self::assertSame($expected, $this->parseProxyResult($url));
    }

    public function testAddsProxyAuthorizationHeaderForSchemeLessCredentials()
    {
        $context = $this->getProxyContext('user:pass@proxy.example.com:8125');

        self::assertSame('tcp://proxy.example.com:8125', $context['http']['proxy']);
        self::assertStringContainsString(
            'Proxy-Authorization: Basic '.\base64_encode('user:pass'),
            $context['http']['header']
        );
    }
}
