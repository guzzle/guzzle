<?php

declare(strict_types=1);

namespace GuzzleHttp\Test\Handler;

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
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\StreamHandler
 */
class StreamHandlerTest extends TestCase
{
    private function queueRes(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '8',
            ], 'hi there'),
        ]);
    }

    public function testReturnsResponseForSuccessfulRequest(): void
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

    public function testRejectsEmptyProtocolVersion(): void
    {
        $handler = new StreamHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('HTTP protocol version must not be empty.');

        $handler(new Request('GET', Server::$url, [], null, ''), []);
    }

    public function testAddsErrorToResponse(): void
    {
        $handler = new StreamHandler();

        $this->expectException(ConnectException::class);
        $handler(
            new Request('GET', 'http://localhost:123'),
            ['timeout' => 0.01]
        )->wait();
    }

    public function testRejectsHttp3(): void
    {
        $handler = new StreamHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('HTTP/3.0 is not supported by the stream handler.');

        $handler(new Request('GET', 'https://example.com', [], null, '3.0'), []);
    }

    public function testStreamAttributeKeepsStreamOpen(): void
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

    public function testDrainsResponseIntoTempStream(): void
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

    public function testDrainsResponseIntoSaveToBody(): void
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

    public function testDrainsResponseIntoSaveToBodyAtPath(): void
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

    public function testDrainsResponseIntoSaveToBodyAtNonExistentPath(): void
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

    public function testDrainsResponseAndReadsOnlyContentLengthBytes(): void
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

    public function testDoesNotDrainWhenHeadRequest(): void
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

    public function testAutomaticallyDecompressGzip(): void
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

    public function testAutomaticallyDecompressGzipHead(): void
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

        // Verify that the content-length matches the encoded size.
        self::assertTrue(!$response->hasHeader('content-length') || $response->getHeaderLine('content-length') == \strlen($content));
    }

    public function testReportsOriginalSizeAndContentEncodingAfterDecoding(): void
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

    public function testDoesNotForceGzipDecode(): void
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

    public function testProtocolVersion(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], null, '1.0');
        $handler($request, []);
        self::assertSame('1.0', Server::received()[0]->getProtocolVersion());
    }

    protected function getSendResult(array $opts): ResponseInterface
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $opts['stream'] = true;
        $request = new Request('GET', Server::$url);

        return $handler($request, $opts)->wait();
    }

    /**
     * @param mixed $proxy
     */
    private function getProxyContext($proxy, string $uri = 'http://example.com'): array
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

    private function applyDefaultTlsMinimum(string $uri, array $context): array
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $method = new \ReflectionMethod(StreamHandler::class, 'addDefaultTlsMinimum');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [$request, &$context]);

        return $context;
    }

    public function testAddsProxy(): void
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->getSendResult(['proxy' => '127.0.0.1:8125']);
    }

    public function testAddsProxyByProtocol(): void
    {
        $url = Server::$url;
        $res = $this->getSendResult(['proxy' => ['http' => $url]]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        foreach ([\PHP_URL_HOST, \PHP_URL_PORT] as $part) {
            self::assertSame(parse_url($url, $part), parse_url($opts['http']['proxy'], $part));
        }
    }

    public function testAddsProxyButHonorsNoProxy(): void
    {
        $url = Server::$url;
        $res = $this->getSendResult(['proxy' => [
            'http' => $url,
            'no' => ['*'],
        ]]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('proxy', $opts['http']);
    }

    public function testAddsProxyButHonorsNoProxyString(): void
    {
        $opts = $this->getProxyContext([
            'http' => 'http://proxy.example.com:8125',
            'no' => 'example.com,localhost',
        ]);

        self::assertArrayNotHasKey('proxy', $opts['http']);
    }

    public function testAddsProxyWithEmptyNoProxyString(): void
    {
        $opts = $this->getProxyContext([
            'http' => 'http://proxy.example.com:8125',
            'no' => '',
        ]);

        self::assertSame('tcp://proxy.example.com:8125', $opts['http']['proxy']);
    }

    /**
     * @dataProvider invalidProxyOptionProvider
     *
     * @param mixed $proxy
     */
    public function testEnsuresProxyOptionShapeIsValid($proxy): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->getProxyContext($proxy);
    }

    public static function invalidProxyOptionProvider(): array
    {
        return [
            [new \stdClass()],
            [['http' => new \stdClass()]],
            [['http' => 'http://proxy.example.com:8125', 'no' => new \stdClass()]],
            [['http' => 'http://proxy.example.com:8125', 'no' => [new \stdClass()]]],
        ];
    }

    public function testAddsProxyButHonorsNoProxyPorts(): void
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

        $proxy['no'] = ['192.168.0.0/16'];
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://192.168.1.10')['http']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://192.169.1.10')['http']['proxy']);

        $proxy['no'] = ['fd00::/8'];
        self::assertArrayNotHasKey('proxy', $this->getProxyContext($proxy, 'http://[fd00::1]')['http']);
        self::assertSame('tcp://proxy.example.com:8125', $this->getProxyContext($proxy, 'http://[fe80::1]')['http']['proxy']);
    }

    public function testUsesProxy(): void
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

    public function testAddsTimeout(): void
    {
        $res = $this->getSendResult(['stream' => true, 'timeout' => 200]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertEquals(200, $opts['http']['timeout']);
    }

    public function testTruncatesStreamTimeoutToMilliseconds(): void
    {
        $res = $this->getSendResult(['stream' => true, 'timeout' => 0.0015]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertEquals(0.001, $opts['http']['timeout']);
    }

    /**
     * @dataProvider invalidStreamTimeoutProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidStreamTimeouts(string $option, $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' must be 0 or greater than or equal to 0.001 seconds');
        $this->getSendResult([$option => $value]);
    }

    public static function invalidStreamTimeoutProvider(): array
    {
        return [
            ['timeout', 0.0001],
            ['timeout', -1],
            ['read_timeout', 0.0001],
            ['read_timeout', -1],
        ];
    }

    public function testVerifiesVerifyIsValidIfPath(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('SSL CA bundle not found: /does/not/exist');

        $this->getSendResult(['verify' => '/does/not/exist']);
    }

    public function testVerifyCanBeDisabled(): void
    {
        $handler = $this->getSendResult(['verify' => false]);
        self::assertInstanceOf(Response::class, $handler);
    }

    public function testVerifiesCertIfValidPath(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('SSL certificate not found: /does/not/exist');

        $this->getSendResult(['cert' => '/does/not/exist']);
    }

    public function testUsesSystemDefaultBundle(): void
    {
        $res = $this->getSendResult(['verify' => true]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('cafile', $opts['ssl']);
    }

    public function testEnsuresVerifyOptionIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid verify request option');

        $this->getSendResult(['verify' => 10]);
    }

    public function testEnsuresCryptoMethodOptionIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method request option: unknown version provided');

        $this->getSendResult(['crypto_method' => 123]);
    }

    public function testDefaultsHttpsToTls12Minimum(): void
    {
        $context = $this->applyDefaultTlsMinimum('https://example.com', ['ssl' => []]);

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $context['ssl']['min_proto_version']);
    }

    public function testDoesNotDefaultTlsMinimumForHttp(): void
    {
        $context = $this->applyDefaultTlsMinimum('http://example.com', ['ssl' => []]);

        self::assertArrayNotHasKey('min_proto_version', $context['ssl']);
    }

    public function testDoesNotDefaultTlsMinimumWhenTlsContextExists(): void
    {
        $context = $this->applyDefaultTlsMinimum('https://example.com', [
            'ssl' => ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT],
        ]);

        self::assertArrayNotHasKey('min_proto_version', $context['ssl']);
    }

    public function testSetsCryptoMethodTls10(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_0, $opts['ssl']['min_proto_version']);
    }

    public function testSetsCryptoMethodTls11(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_1, $opts['ssl']['min_proto_version']);
    }

    public function testSetsCryptoMethodTls12(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $opts['ssl']['min_proto_version']);
    }

    public function testSetsCryptoMethodTls13(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_3, $opts['ssl']['min_proto_version']);
    }

    public function testStreamContextTlsMinimumOverridesCryptoMethod(): void
    {
        $res = $this->getSendResult([
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'stream_context' => [
                'ssl' => ['min_proto_version' => \STREAM_CRYPTO_PROTO_TLSv1_0],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_0, $opts['ssl']['min_proto_version']);
    }

    public function testStreamContextCryptoMethodOverridesCryptoMethod(): void
    {
        $res = $this->getSendResult([
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'stream_context' => [
                'ssl' => ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT, $opts['ssl']['crypto_method']);
        self::assertArrayNotHasKey('min_proto_version', $opts['ssl']);
    }

    public function testCanSetPasswordWhenSettingCert(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult(['cert' => [$path, 'foo']]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testCanSetCertWithArrayPathOnly(): void
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

    /**
     * @dataProvider invalidCertOptionProvider
     *
     * @param mixed $cert
     */
    public function testEnsuresCertOptionShapeIsValid($cert): void
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

    public function testDebugAttributeWritesToStream(): void
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

    public function testDebugAttributeWritesStreamInfoToBuffer(): void
    {
        $called = false;
        $this->queueRes();
        $buffer = \fopen('php://temp', 'r+');
        $this->getSendResult([
            'progress' => static function () use (&$called): void {
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

    public function testEmitsProgressInformation(): void
    {
        $called = [];
        $this->queueRes();
        $this->getSendResult([
            'progress' => static function (...$args) use (&$called): void {
                $called[] = $args;
            },
        ]);
        self::assertNotEmpty($called);
        self::assertEquals(8, $called[0][0]);
        self::assertEquals(0, $called[0][1]);
    }

    public function testEmitsProgressInformationAndDebugInformation(): void
    {
        $called = [];
        $this->queueRes();
        $buffer = \fopen('php://memory', 'w+');
        $this->getSendResult([
            'debug' => $buffer,
            'progress' => static function (...$args) use (&$called): void {
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

    public function testPerformsShallowMergeOfCustomContextOptions(): void
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
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame('HEAD', $opts['http']['method']);
        self::assertTrue($opts['http']['request_fulluri']);
        self::assertSame('127.0.0.1:0', $opts['socket']['bindto']);
        self::assertFalse($opts['ssl']['verify_peer']);
    }

    public function testEnsuresThatStreamContextIsAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_context must be an array');

        $this->getSendResult(['stream_context' => 'foo']);
    }

    public function testDoesNotAddContentTypeByDefault(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, ['Content-Length' => '3'], 'foo');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals('', $req->getHeaderLine('Content-Type'));
        self::assertEquals(3, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthByDefault(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], 'foo');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(3, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthForPUTEvenWhenEmpty(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthForPOSTEvenWhenEmpty(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('POST', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testDontAddContentLengthForGETEvenWhenEmpty(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertSame('', $req->getHeaderLine('Content-Length'));
    }

    public function testSupports100Continue(): void
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

    public function testDoesSleep(): void
    {
        $response = new Response(200);
        Server::enqueue([$response]);
        $a = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $s = Utils::currentTime();
        $a($request, ['delay' => 0.1])->wait();
        self::assertGreaterThan(0.0001, Utils::currentTime() - $s);
    }

    public function testEnsuresOnHeadersIsCallable(): void
    {
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $handler($req, ['on_headers' => 'error!']);
    }

    public function testEnsuresProgressIsCallable(): void
    {
        $req = new Request('GET', 'http://example.com');
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('progress client option must be callable');
        $handler($req, ['progress' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_headers' => static function (): void {
                throw new \Exception('test');
            },
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An error was encountered during the on_headers event');
        $promise->wait();
    }

    public function testRejectsPromiseWhenOnHeadersThrowsThrowable(): void
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

    public function testSuccessfullyCallsOnHeadersBeforeWritingToSink(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $got = null;
        $gotRequest = null;

        $stream = Psr7\Utils::streamFor();
        $stream = FnStream::decorate($stream, [
            'write' => static function (string $data) use ($stream, &$got): int {
                self::assertNotNull($got);

                return $stream->write($data);
            },
        ]);

        $handler = new StreamHandler();
        $promise = $handler($req, [
            'sink' => $stream,
            'on_headers' => static function (
                ResponseInterface $res,
                RequestInterface $request
            ) use (&$got, &$gotRequest, $req): void {
                $got = $res;
                $gotRequest = $request;
                self::assertSame($req, $request);
                self::assertSame('bar', $res->getHeaderLine('X-Foo'));
            },
        ]);

        $response = $promise->wait();
        self::assertSame($req, $gotRequest);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('abc 123', (string) $response->getBody());
    }

    public function testInvokesOnStatsOnSuccess(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_stats' => static function (TransferStats $stats) use (&$gotStats): void {
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

    public function testInvokesOnStatsOnError(): void
    {
        $req = new Request('GET', 'http://127.0.0.1:123');
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
            'on_stats' => static function (TransferStats $stats) use (&$gotStats): void {
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

    public function testStreamIgnoresZeroTimeout(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 10,
            'timeout' => 0,
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDrainsResponseAndReadsAllContentWhenContentLengthIsZero(): void
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

    public function testHonorsReadTimeout(): void
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

    public function testHandlesGarbageHttpServerGracefully(): void
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

    public function testHandlesInvalidStatusCodeGracefully(): void
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
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertFalse($stats->hasResponse());
            self::assertNull($stats->getResponse());
            self::assertSame($e, $stats->getHandlerErrorData());
        }
    }

    public function testRejectsNonHttpSchemes(): void
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
}
