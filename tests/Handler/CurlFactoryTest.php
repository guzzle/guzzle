<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\CurlFactory
 */
class CurlFactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
    }

    public static function tearDownAfterClass(): void
    {
        unset($_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_test'], $_SERVER['curl_setopt_fail']);
    }

    public function testCreatesCurlHandle()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [
                'Foo' => 'Bar',
                'Baz' => 'bam',
                'Content-Length' => '2',
            ], 'hi'),
        ]);
        $stream = Psr7\Utils::streamFor();
        $request = new Psr7\Request('PUT', Server::$url, [
            'Hi' => ' 123',
            'Content-Length' => '7',
        ], 'testing');
        $f = new CurlFactory(3);

        $result = $f->create($request, ['sink' => $stream]);

        try {
            self::assertInstanceOf(EasyHandle::class, $result);

            if (\PHP_VERSION_ID >= 80000) {
                self::assertInstanceOf(\CurlHandle::class, $result->handle);
            } else {
                self::assertIsResource($result->handle);
            }

            self::assertIsArray($result->headers);
            self::assertSame($stream, $result->sink);
        } finally {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($result->handle);
            }
        }

        self::assertSame('PUT', $_SERVER['_curl'][\CURLOPT_CUSTOMREQUEST]);
        self::assertSame(
            'http://127.0.0.1:8126/',
            $_SERVER['_curl'][\CURLOPT_URL]
        );
        // Sends via post fields when the request is small enough
        self::assertSame('testing', $_SERVER['_curl'][\CURLOPT_POSTFIELDS]);
        self::assertEquals(0, $_SERVER['_curl'][\CURLOPT_RETURNTRANSFER]);
        self::assertEquals(0, $_SERVER['_curl'][\CURLOPT_HEADER]);
        self::assertSame(300, $_SERVER['_curl'][\CURLOPT_CONNECTTIMEOUT]);
        self::assertInstanceOf('Closure', $_SERVER['_curl'][\CURLOPT_HEADERFUNCTION]);
        if (\defined('CURLOPT_PROTOCOLS')) {
            self::assertSame(
                \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
                $_SERVER['_curl'][\CURLOPT_PROTOCOLS]
            );
        }
        self::assertContains('Expect:', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Accept:', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Content-Type:', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Hi: 123', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Host: 127.0.0.1:8126', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
    }

    public function testSendsHeadRequests()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), []);
        $response->wait();
        self::assertTrue($_SERVER['_curl'][\CURLOPT_NOBODY]);
        self::assertArrayNotHasKey(\CURLOPT_CUSTOMREQUEST, $_SERVER['_curl']);
        $checks = [\CURLOPT_READFUNCTION, \CURLOPT_FILE, \CURLOPT_INFILE];
        foreach ($checks as $check) {
            self::assertArrayNotHasKey($check, $_SERVER['_curl']);
        }
        self::assertEquals('HEAD', Server::received()[0]->getMethod());
    }

    public function testHeadRequestsWithABodyDoNotWaitForAResponseBody()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200, ['Content-Length' => '16'], 'Body of response')]);
        $a = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('HEAD', Server::$url, ['Content-Length' => '5'], 'hello');
        $response = $a($request, ['timeout' => 5])->wait();
        self::assertTrue($_SERVER['_curl'][\CURLOPT_NOBODY]);
        self::assertArrayNotHasKey(\CURLOPT_CUSTOMREQUEST, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_UPLOAD, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_POSTFIELDS, $_SERVER['_curl']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('16', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
        $received = Server::received()[0];
        self::assertEquals('HEAD', $received->getMethod());
        self::assertFalse($received->hasHeader('Content-Length'));
        self::assertFalse($received->hasHeader('Transfer-Encoding'));
        self::assertSame('', (string) $received->getBody());
    }

    public function testHeadRequestsWithAnUnknownBodySizeUseNobody()
    {
        $body = new Psr7\PumpStream(static function () {
            return false;
        });
        $factory = new CurlFactory(1);
        $easy = null;

        try {
            $easy = $factory->create(new Psr7\Request('HEAD', Server::$url, ['Transfer-Encoding' => 'chunked', 'Expect' => '100-continue'], $body), []);

            self::assertTrue($_SERVER['_curl'][\CURLOPT_NOBODY]);
            self::assertArrayNotHasKey(\CURLOPT_CUSTOMREQUEST, $_SERVER['_curl']);
            self::assertArrayNotHasKey(\CURLOPT_UPLOAD, $_SERVER['_curl']);
            self::assertArrayNotHasKey(\CURLOPT_READFUNCTION, $_SERVER['_curl']);
            self::assertNotContains('Transfer-Encoding: chunked', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
            self::assertNotContains('Expect: 100-continue', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        } finally {
            if ($easy !== null) {
                $factory->release($easy);
            }
        }
    }

    public function testHeadRequestsPreserveZeroContentLength()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url, ['Content-Length' => '0']), []);
        $response->wait();

        self::assertTrue($_SERVER['_curl'][\CURLOPT_NOBODY]);
        $received = Server::received()[0];
        self::assertEquals('HEAD', $received->getMethod());
        self::assertSame('0', $received->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $received->getBody());
    }

    public function testHeadRequestsNeverProbeTheBodySize()
    {
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('hello'), [
            'getSize' => static function () {
                throw new \RuntimeException('The body must not be probed for HEAD requests.');
            },
        ]);
        $factory = new CurlFactory(1);
        $easy = null;

        try {
            $easy = $factory->create(new Psr7\Request('HEAD', Server::$url, [], $body), []);

            self::assertTrue($_SERVER['_curl'][\CURLOPT_NOBODY]);
            self::assertArrayNotHasKey(\CURLOPT_CUSTOMREQUEST, $_SERVER['_curl']);
        } finally {
            if ($easy !== null) {
                $factory->release($easy);
            }
        }
    }

    public function testCanAddCustomCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);
        $a($req, ['curl' => [\CURLOPT_LOW_SPEED_LIMIT => 10]]);
        self::assertEquals(10, $_SERVER['_curl'][\CURLOPT_LOW_SPEED_LIMIT]);
    }

    public function testCanAddPrereqFunctionCurlOption(): void
    {
        if (!\defined('CURLOPT_PREREQFUNCTION')) {
            self::markTestSkipped('CURLOPT_PREREQFUNCTION is not available.');
        }
        if (!\defined('CURL_PREREQFUNC_OK')) {
            self::markTestSkipped('CURL_PREREQFUNC_OK is not available.');
        }

        $option = (int) \constant('CURLOPT_PREREQFUNCTION');
        $ok = (int) \constant('CURL_PREREQFUNC_OK');
        $callback = static function () use ($ok): int {
            return $ok;
        };

        $factory = new CurlFactory(1);
        $easy = null;

        try {
            $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
                'curl' => [
                    $option => $callback,
                ],
            ]);

            self::assertSame($callback, $_SERVER['_curl'][$option]);
        } finally {
            if ($easy !== null) {
                $factory->release($easy);
            }
        }
    }

    public function testCertinfoIsInSupportedCurlOptionsAllowList(): void
    {
        $method = new \ReflectionMethod(CurlFactory::class, 'supportedCurlOptions');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        /** @var array<int, true> $supported */
        $supported = $method->invoke(null);

        self::assertArrayHasKey(
            \CURLOPT_CERTINFO,
            $supported,
            'CURLOPT_CERTINFO must be in the built-in cURL handlers\' allow-list so it no longer triggers the raw cURL option deprecation.'
        );
    }

    public function testPrereqFunctionIsInSupportedCurlOptionsAllowList(): void
    {
        if (!\defined('CURLOPT_PREREQFUNCTION')) {
            self::markTestSkipped('CURLOPT_PREREQFUNCTION is not available.');
        }

        $option = (int) \constant('CURLOPT_PREREQFUNCTION');
        $method = new \ReflectionMethod(CurlFactory::class, 'supportedCurlOptions');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        /** @var array<int, true> $supported */
        $supported = $method->invoke(null);

        self::assertArrayHasKey(
            $option,
            $supported,
            'CURLOPT_PREREQFUNCTION must be in the built-in cURL handlers\' allow-list so it no longer triggers the raw cURL option deprecation.'
        );
    }

    public function testPrereqFunctionIsClearedBeforeReusingCurlHandle(): void
    {
        if (!\defined('CURLOPT_PREREQFUNCTION')) {
            self::markTestSkipped('CURLOPT_PREREQFUNCTION is not available.');
        }
        if (!\defined('CURL_PREREQFUNC_OK')) {
            self::markTestSkipped('CURL_PREREQFUNC_OK is not available.');
        }

        $option = (int) \constant('CURLOPT_PREREQFUNCTION');
        $ok = (int) \constant('CURL_PREREQFUNC_OK');

        Server::flush();
        Server::enqueue([
            new Psr7\Response(200),
            new Psr7\Response(200),
        ]);

        $factory = new CurlFactory(1);
        $handler = new Handler\CurlHandler(['handle_factory' => $factory]);
        $called = 0;
        $request = new Psr7\Request('GET', Server::$url);

        $handler($request, [
            'curl' => [
                $option => static function () use (&$called, $ok): int {
                    ++$called;

                    return $ok;
                },
            ],
        ])->wait();

        $afterFirst = $called;
        self::assertSame(1, $afterFirst);

        $handler($request, [
            'curl' => [
                \CURLOPT_FRESH_CONNECT => true,
            ],
        ])->wait();

        self::assertSame($afterFirst, $called);
    }

    public function testPrereqFunctionAbortUsesExistingCurlErrorPath(): void
    {
        if (!\defined('CURLOPT_PREREQFUNCTION')) {
            self::markTestSkipped('CURLOPT_PREREQFUNCTION is not available.');
        }
        if (!\defined('CURL_PREREQFUNC_ABORT')) {
            self::markTestSkipped('CURL_PREREQFUNC_ABORT is not available.');
        }

        $option = (int) \constant('CURLOPT_PREREQFUNCTION');
        $abort = (int) \constant('CURL_PREREQFUNC_ABORT');
        $called = 0;

        Server::flush();

        $handler = new Handler\CurlHandler(['handle_factory' => new CurlFactory(1)]);
        $promise = $handler(new Psr7\Request('GET', Server::$url), [
            'curl' => [
                $option => static function () use (&$called, $abort): int {
                    ++$called;

                    return $abort;
                },
            ],
        ]);

        try {
            $promise->wait();
            self::fail('Expected a RequestException from the aborted prereq callback.');
        } catch (RequestException $e) {
            self::assertStringContainsString('cURL error', $e->getMessage());
            self::assertSame(1, $called);
        }
    }

    public function testRejectsCurlProxyHeaderEntriesContainingNewlines(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        $factory = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXYHEADER');

        $factory->create(new Psr7\Request('GET', 'http://example.com'), [
            'curl' => [
                $proxyHeaderOption => ["X-Decoy: v\r\nProxy-Authorization: Basic abc"],
            ],
        ]);
    }

    public function testRejectsCurlHttpHeaderEntriesContainingNewlines(): void
    {
        $conf = [\CURLOPT_HTTPHEADER => ["X-Decoy: v\r\nProxy-Authorization: Basic abc"]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_HTTPHEADER');

        self::normalizeCurlHeaderOptions($conf);
    }

    public function testNormalizesStringableCurlHeaderEntriesBeforeProxyTunnelSignature(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        $conf = [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            $proxyHeaderOption => [new class {
                public function __toString(): string
                {
                    return 'Proxy-Authorization: Basic abc';
                }
            }],
        ];

        self::normalizeCurlHeaderOptions($conf);

        self::assertSame(['Proxy-Authorization: Basic abc'], $conf[$proxyHeaderOption]);
        $delegated = self::computeProxyTunnelSignature('8.20.0', 'https://example.com', [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
        ]);
        $literalHeader = self::computeProxyTunnelSignature('8.20.0', 'https://example.com', $conf);
        self::assertNotNull($literalHeader);
        self::assertNotSame($delegated, $literalHeader);
    }

    public function testNormalizesScalarCurlHeaderEntries(): void
    {
        $conf = [
            \CURLOPT_HTTPHEADER => [
                'string' => 'X-String: value',
                'int' => 123,
                'float' => 1.5,
                'true' => true,
                'false' => false,
                'nan' => \NAN,
                'inf' => \INF,
                '-inf' => -\INF,
            ],
        ];

        self::normalizeCurlHeaderOptions($conf);

        self::assertSame([
            'string' => 'X-String: value',
            'int' => '123',
            'float' => '1.5',
            'true' => '1',
            'false' => '',
            'nan' => 'NAN',
            'inf' => 'INF',
            '-inf' => '-INF',
        ], $conf[\CURLOPT_HTTPHEADER]);
    }

    /**
     * @dataProvider invalidCurlHeaderEntryProvider
     *
     * @param mixed $entry
     */
    public function testRejectsInvalidCurlHeaderEntries($entry): void
    {
        $conf = [\CURLOPT_HTTPHEADER => [$entry]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_HTTPHEADER entries must be strings, stringable objects, or scalar values.');

        self::normalizeCurlHeaderOptions($conf);
    }

    public static function invalidCurlHeaderEntryProvider(): iterable
    {
        yield 'null' => [null];
        yield 'array' => [[]];
        yield 'non-stringable object' => [new \stdClass()];
    }

    public function testRejectsResourceCurlHeaderEntries(): void
    {
        $resource = \fopen(__FILE__, 'r');
        self::assertIsResource($resource);

        try {
            $conf = [\CURLOPT_HTTPHEADER => [$resource]];

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CURLOPT_HTTPHEADER entries must be strings, stringable objects, or scalar values.');

            self::normalizeCurlHeaderOptions($conf);
        } finally {
            \fclose($resource);
        }
    }

    public function testRejectsStringableCurlHeaderEntriesContainingNewlines(): void
    {
        $conf = [
            \CURLOPT_HTTPHEADER => [new class {
                public function __toString(): string
                {
                    return "X-Test: value\r\nInjected: yes";
                }
            }],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_HTTPHEADER entries must not contain a carriage return or line feed.');

        self::normalizeCurlHeaderOptions($conf);
    }

    public function testRejectsInvalidCurlProxyHeaderEntries(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        $conf = [$proxyHeaderOption => [[]]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXYHEADER entries must be strings, stringable objects, or scalar values.');

        self::normalizeCurlHeaderOptions($conf);
    }

    public function testLeavesNormalCurlHeaderEntriesUnchanged(): void
    {
        $conf = [\CURLOPT_HTTPHEADER => ['Accept: application/json']];

        self::normalizeCurlHeaderOptions($conf);

        self::assertSame(['Accept: application/json'], $conf[\CURLOPT_HTTPHEADER]);
    }

    public function testAppliesConfiguredCurlShareHandle(): void
    {
        self::skipIfCurlShareIsUnavailable();
        unset($_SERVER['_curl']);

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);

        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        try {
            self::assertSame($shareHandle, $_SERVER['_curl'][\CURLOPT_SHARE]);
        } finally {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($easy->handle);
                \curl_share_close($shareHandle);
            }
        }
    }

    public function testRejectsRequestLevelShareWhenConfiguredCurlShareHandleExists(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        $requestShareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        self::assertNotFalse($requestShareHandle);
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CURLOPT_SHARE');

            $factory->create(new Psr7\Request('GET', Server::$url), [
                'curl' => [
                    \CURLOPT_SHARE => $requestShareHandle,
                ],
            ]);
        } finally {
            if (PHP_VERSION_ID < 80000) {
                \curl_share_close($shareHandle);
                \curl_share_close($requestShareHandle);
            }
        }
    }

    public function testRejectsRequestLevelShareWithProxyUrlCredentials(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $conf = [\CURLOPT_PROXY => 'http://username:password@proxy.example.com:8080'];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated HTTP/HTTPS proxy tunnel configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testRejectsRequestLevelShareWithProxyUserPwd(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $conf = [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            \CURLOPT_PROXYUSERPWD => 'username:password',
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated HTTP/HTTPS proxy tunnel configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testRejectsRequestLevelShareWithProxyAuthorizationHeader(): void
    {
        self::skipIfCurlShareIsUnavailable();
        $proxyHeaderOption = self::proxyHeaderOption();

        $conf = [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            $proxyHeaderOption => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated HTTP/HTTPS proxy tunnel configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testRejectsRequestLevelShareWithStringableProxyAuthorizationHeader(): void
    {
        self::skipIfCurlShareIsUnavailable();
        $proxyHeaderOption = self::proxyHeaderOption();

        $conf = [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            $proxyHeaderOption => [new class {
                public function __toString(): string
                {
                    return 'Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=';
                }
            }],
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];
        self::normalizeCurlHeaderOptions($conf);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated HTTP/HTTPS proxy tunnel configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testAllowsRequestLevelShareWithProxyAuthorizationHeaderWhenRawNoProxyDisablesProxy(): void
    {
        self::skipIfCurlShareIsUnavailable();
        self::skipIfCurlNoProxyIsUnavailable();
        $proxyHeaderOption = self::proxyHeaderOption();

        $conf = [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            (int) \constant('CURLOPT_NOPROXY') => '*',
            $proxyHeaderOption => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertNull($method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf));
    }

    public function testRejectsRequestLevelShareWithLegacyProxyAuthorizationHeader(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $conf = [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            \CURLOPT_HTTPHEADER => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated HTTP/HTTPS proxy tunnel configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testRejectsRequestLevelShareWithProxyTlsCredential(): void
    {
        self::skipIfCurlShareIsUnavailable();
        if (!\defined('CURLOPT_PROXY_SSLCERT')) {
            self::markTestSkipped('CURLOPT_PROXY_SSLCERT is not available.');
        }

        $conf = [
            \CURLOPT_PROXY => 'https://proxy.example.com:3128',
            (int) \constant('CURLOPT_PROXY_SSLCERT') => '/path/to/proxy-client.pem',
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated HTTP/HTTPS proxy tunnel configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testRejectsRequestLevelShareWithSocksProxyUrlCredentials(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $conf = [\CURLOPT_PROXY => 'socks5://username:password@proxy.example.com:1080'];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated SOCKS proxy configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testRejectsRequestLevelShareWithSocksProxyUserPwd(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $conf = [
            \CURLOPT_PROXY => 'socks5://proxy.example.com:1080',
            \CURLOPT_PROXYUSERPWD => 'username:password',
        ];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#CURLOPT_SHARE.*authenticated SOCKS proxy configuration#');

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf);
    }

    public function testAllowsRequestLevelShareWithAnonymousSocksProxy(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $conf = [\CURLOPT_PROXY => 'socks5://proxy.example.com:1080'];
        $options = ['curl' => [(int) \constant('CURLOPT_SHARE') => null]];

        $method = new \ReflectionMethod(CurlFactory::class, 'rejectRequestLevelShareWithProxyAuth');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertNull($method->invoke(null, new Psr7\Request('GET', 'https://example.com'), $options, $conf));
    }

    /**
     * @dataProvider requestTransportSharingOptionProvider
     *
     * @param mixed $transportSharing
     */
    public function testIgnoresRequestLevelTransportSharingOption($transportSharing): void
    {
        unset($_SERVER['_curl']);

        $easy = (new CurlFactory(3))->create(new Psr7\Request('GET', Server::$url), [
            'transport_sharing' => $transportSharing,
        ]);

        try {
            if (\defined('CURLOPT_SHARE')) {
                self::assertArrayNotHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
            }
        } finally {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($easy->handle);
            }
        }
    }

    public static function requestTransportSharingOptionProvider(): iterable
    {
        yield 'null' => [null];
        yield 'none' => [TransportSharing::NONE];
        yield 'handler prefer' => [TransportSharing::HANDLER_PREFER];
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'invalid' => ['invalid'];
    }

    public function testRejectsEnabledShareModeWithoutShareHandle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('share handle is required');

        new CurlFactory(3, TransportSharing::HANDLER_PREFER);
    }

    public function testRejectsShareHandleWhenSharingIsDisabled(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('cannot be provided');

            new CurlFactory(3, TransportSharing::NONE, $shareHandle);
        } finally {
            if (PHP_VERSION_ID < 80000) {
                \curl_share_close($shareHandle);
            }
        }
    }

    public function testRejectsInvalidShareHandle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cURL share handle');

        new CurlFactory(3, TransportSharing::HANDLER_PREFER, false);
    }

    public function testCanChangeCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);
        $a($req, ['curl' => [\CURLOPT_LOW_SPEED_TIME => 10]]);
        self::assertEquals(10, $_SERVER['_curl'][\CURLOPT_LOW_SPEED_TIME]);
    }

    public function testProtocolsOptionCanRestrictCurlProtocols()
    {
        if (!\defined('CURLOPT_PROTOCOLS')) {
            self::markTestSkipped('CURLOPT_PROTOCOLS is not available.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'https://example.com'), ['protocols' => ['https']]);

        self::assertSame(\CURLPROTO_HTTPS, $_SERVER['_curl'][\CURLOPT_PROTOCOLS]);
    }

    public function testProtocolsOptionRejectsDisallowedCurlScheme()
    {
        $f = new CurlFactory(3);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('not allowed by the protocols request option');

        $f->create(new Psr7\Request('GET', 'http://example.com'), ['protocols' => ['https']]);
    }

    public function testRejectsUnsupportedScheme()
    {
        $f = new CurlFactory(3);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("The scheme 'ftp' is not supported.");

        $f->create(new Psr7\Request('GET', 'ftp://example.com'), []);
    }

    public function testRejectsUnsupportedSchemeBeforeMissingHost()
    {
        $f = new CurlFactory(3);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("The scheme 'file' is not supported.");

        $f->create(new Psr7\Request('GET', 'file:///etc/passwd'), []);
    }

    public function testProtocolsOptionRejectsBeforeMissingHost()
    {
        $f = new CurlFactory(3);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('not allowed by the protocols request option');

        $f->create(new Psr7\Request('GET', 'http:/generate_204'), ['protocols' => ['https']]);
    }

    /**
     * @dataProvider uriMissingSchemeOrHostProvider
     */
    public function testRejectsRequestUriMissingSchemeOrHost($uri)
    {
        $f = new CurlFactory(3);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('URI must include a scheme and host');

        $f->create(new Psr7\Request('GET', $uri), []);
    }

    public static function uriMissingSchemeOrHostProvider(): iterable
    {
        yield 'relative path' => ['baz'];
        yield 'host-like relative path' => ['gstatic.com/generate_204'];
        yield 'path starting with colon-slash-slash' => ['://gstatic.com/generate_204'];
        yield 'absolute path' => ['/generate_204'];
        yield 'scheme without host' => ['https:/generate_204'];
    }

    /**
     * @dataProvider invalidProtocolsProvider
     *
     * @param mixed $protocols
     */
    public function testProtocolsOptionRejectsInvalidValues($protocols)
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('protocols');

        $f->create(new Psr7\Request('GET', 'http://example.com'), ['protocols' => $protocols]);
    }

    public static function invalidProtocolsProvider(): array
    {
        return [
            'empty' => [[]],
            'non-array' => ['https'],
            'non-string' => [[123]],
            'unsupported' => [['ftp']],
        ];
    }

    public function testThrowsWhenCurlOptionCannotBeApplied()
    {
        $_SERVER['curl_setopt_fail'] = \CURLOPT_LOW_SPEED_LIMIT;
        $f = new CurlFactory(3);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Unable to set cURL option CURLOPT_LOW_SPEED_LIMIT');

            $f->create(
                new Psr7\Request('GET', Server::$url),
                ['curl' => [\CURLOPT_LOW_SPEED_LIMIT => 10]]
            );
        } finally {
            unset($_SERVER['curl_setopt_fail']);
        }
    }

    public function testThrowsWhenCurlOptionNameIsInvalid()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cURL option "not-a-curl-option".');

        $f->create(
            new Psr7\Request('GET', Server::$url),
            ['curl' => ['not-a-curl-option' => true]]
        );
    }

    public function testValidatesVerify()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL CA bundle not found: /does/not/exist');
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => '/does/not/exist']);
    }

    public function testCanSetVerifyToFile()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_CAINFO]);
        self::assertEquals(2, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
    }

    public function testCanSetVerifyToDir()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __DIR__]);
        self::assertEquals(__DIR__, $_SERVER['_curl'][\CURLOPT_CAPATH]);
        self::assertEquals(2, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsVerifyAsTrue()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => true]);
        self::assertEquals(2, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
        self::assertArrayNotHasKey(\CURLOPT_CAINFO, $_SERVER['_curl']);
    }

    public function testCanDisableVerify()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => false]);
        self::assertEquals(0, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsProxy()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['proxy' => 'http://bar.com']);
        self::assertEquals('http://bar.com', $_SERVER['_curl'][\CURLOPT_PROXY]);
        self::assertNoProxyOption('');
    }

    public function testAddsViaScheme()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'proxy' => ['http' => 'http://bar.com', 'https' => 'https://t'],
        ]);
        self::assertEquals('http://bar.com', $_SERVER['_curl'][\CURLOPT_PROXY]);
        $this->checkNoProxyForHost('http://test.test.com', 'test.test.com', false);
        $this->checkNoProxyForHost('http://test.test.com', 'other.test.com, test.test.com', false);
        $this->checkNoProxyForHost('http://test.test.com', ' other.test.com , test.test.com ', false);
        $this->checkNoProxyForHost('http://test.test.com', 'test.test.com:80', false);
        $this->checkNoProxyForHost('http://test.test.com', '*', false);
        $this->checkNoProxyForHost('http://test.test.com', '', true);
        $this->checkNoProxyForHost('http://test.test.com', null, true);
        $this->checkNoProxyForHost('http://test.test.com', [' test.test.com ', new \stdClass()], false);
        $this->checkNoProxyForHost('http://test.test.com', ['test.test.com'], false);
        $this->checkNoProxyForHost('http://test.test.com', ['.test.com'], false);
        $this->checkNoProxyForHost('http://test.test.com', ['test.test.com:80'], false);
        $this->checkNoProxyForHost('https://test.test.com', ['test.test.com:443'], false);
        $this->checkNoProxyForHost('http://test.test.com:8080', ['test.test.com:8080'], false);
        $this->checkNoProxyForHost('http://test.test.com:8081', ['test.test.com:8080'], true);
        $this->checkNoProxyForHost('http://foo.test.com:8080', ['.test.com:8080'], false);
        $this->checkNoProxyForHost('http://test.com:8080', ['.test.com:8080'], true);
        $this->checkNoProxyForHost('http://[::1]:8080', ['[::1]:8080'], false);
        $this->checkNoProxyForHost('http://[::1]:8081', ['[::1]:8080'], true);
        $this->checkNoProxyForHost('http://test.test.com', ['*.test.com'], true);
        $this->checkNoProxyForHost('http://test.test.com', ['*'], false);
        $this->checkNoProxyForHost('http://127.0.0.1', ['127.0.0.*'], true);
        $this->checkNoProxyForHost('http://10.1.2.3', ['10.0.0.0/8'], false);
        $this->checkNoProxyForHost('http://11.1.2.3', ['10.0.0.0/8'], true);
        $this->checkNoProxyForHost('http://[fd00::1]', ['fd00::/8'], false);
    }

    public function testPinsProxyOptionsWhenNoProxyIsConfigured(): void
    {
        self::withProxyEnvironment([], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', Server::$url), []);

            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testResolvesLowercaseProxyEnvironmentVariable(): void
    {
        self::withProxyEnvironment(['http_proxy' => 'http://proxy.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $easy = $f->create(new Psr7\Request('GET', 'http://example.com'), []);

            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
            self::assertSame('http://proxy.example.com:8125', $easy->effectiveProxy);
        });
    }

    public function testResolvesUppercaseHttpsProxyEnvironmentVariable(): void
    {
        self::withProxyEnvironment(['HTTPS_PROXY' => 'http://proxy.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), []);

            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testLowercaseProxyEnvironmentVariableTakesPrecedence(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment([
            'https_proxy' => 'http://lower.example.com:8125',
            'HTTPS_PROXY' => 'http://upper.example.com:8125',
        ], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), []);

            self::assertSame('http://lower.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testNeverReadsUppercaseHttpProxyEnvironmentVariable(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment(['HTTP_PROXY' => 'http://proxy.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'http://example.com'), []);

            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testResolvesAllProxyEnvironmentVariableForAnyScheme(): void
    {
        self::withProxyEnvironment(['ALL_PROXY' => 'http://proxy.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'http://example.com'), []);
            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');

            $f->create(new Psr7\Request('GET', 'https://example.com'), []);
            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testTreatsEmptyProxyEnvironmentVariableAsUnset(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => '',
            'ALL_PROXY' => 'http://proxy.example.com:8125',
        ], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), []);

            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testMatchesEnvironmentNoProxyAgainstTheRequest(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => 'http://proxy.example.com:8125',
            'NO_PROXY' => '10.0.0.0/8,example.com, .internal',
        ], static function (): void {
            $f = new CurlFactory(3);

            $easy = $f->create(new Psr7\Request('GET', 'https://example.com'), []);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');
            self::assertNull($easy->effectiveProxy);

            $f->create(new Psr7\Request('GET', 'https://10.1.2.3'), []);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');

            $f->create(new Psr7\Request('GET', 'https://foo.com'), []);
            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testTokenizesEnvironmentNoProxyLikeLibcurl(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => 'http://proxy.example.com:8125',
            'NO_PROXY' => '.internal.test host1.test host2.test',
        ], static function (): void {
            $f = new CurlFactory(3);

            // A leading dot is ignored, so the root domain is bypassed too.
            $f->create(new Psr7\Request('GET', 'https://internal.test'), []);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');

            // Blanks separate entries just like commas.
            $f->create(new Psr7\Request('GET', 'https://host2.test'), []);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');

            $f->create(new Psr7\Request('GET', 'https://other.test'), []);
            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testLowercaseNoProxyEnvironmentVariableTakesPrecedence(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment([
            'https_proxy' => 'http://proxy.example.com:8125',
            'no_proxy' => 'lower.example.com',
            'NO_PROXY' => 'upper.example.com',
        ], static function (): void {
            $f = new CurlFactory(3);

            $f->create(new Psr7\Request('GET', 'https://lower.example.com'), []);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');

            $f->create(new Psr7\Request('GET', 'https://upper.example.com'), []);
            self::assertSame('http://proxy.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testProxyOptionDisablesEnvironmentProxyResolution(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => 'http://env.example.com:8125',
            'NO_PROXY' => 'example.com',
        ], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => 'http://option.example.com:8125',
            ]);

            self::assertSame('http://option.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testProxyOptionEmptyStringDisablesEnvironmentProxyResolution(): void
    {
        self::withProxyEnvironment(['https_proxy' => 'http://env.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => '',
            ]);

            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testNoProxyMatchIsNotReProxiedFromEnvironment(): void
    {
        self::withProxyEnvironment(['http_proxy' => 'http://env.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'http://internal.example.com'), [
                'proxy' => [
                    'http' => 'http://option.example.com:8125',
                    'no' => ['internal.example.com'],
                ],
            ]);

            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');
        });
    }

    public function testFallsBackToEnvironmentWhenSchemeIsNotConfigured(): void
    {
        self::withProxyEnvironment(['https_proxy' => 'http://env.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => ['http' => 'http://option.example.com:8125'],
            ]);

            self::assertSame('http://env.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        });
    }

    public function testRawCurlProxyOptionOverridesPinnedProxy(): void
    {
        self::withProxyEnvironment(['https_proxy' => 'http://env.example.com:8125'], static function (): void {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'curl' => [\CURLOPT_PROXY => 'http://raw.example.com:8125'],
            ]);

            self::assertSame('http://raw.example.com:8125', $_SERVER['_curl'][\CURLOPT_PROXY]);
        });
    }

    public function testSectionsEnvironmentCredentialedProxyOnAffectedCurlVersion(): void
    {
        self::withProxyEnvironment(['https_proxy' => 'http://username:password@proxy.example.com:8080'], static function (): void {
            $factory = new CurlFactory(3);
            $easy = self::createOnFactory($factory, '8.19.0', 'https://example.com', []);

            self::assertNotNull($easy->proxyTunnelSignature);
        });
    }

    public function testDelegatesEnvironmentCredentialedProxyOnFixedCurlVersion(): void
    {
        self::withProxyEnvironment(['https_proxy' => 'http://username:password@proxy.example.com:8080'], static function (): void {
            $factory = new CurlFactory(3);
            $easy = self::createOnFactory($factory, '8.20.0', 'https://example.com', []);

            self::assertNotNull($easy->proxyTunnelSignature);
        });
    }

    public static function unsupportedHttpsProxyCurlVersionProvider(): array
    {
        return [
            ['7.21.2'],
            ['7.50.0'],
            ['7.51.0'],
            ['7.52.0'],
            ['7.61.0'],
        ];
    }

    /**
     * @dataProvider unsupportedHttpsProxyCurlVersionProvider
     */
    public function testRejectsHttpsProxyWhenLibcurlLacksSupport(string $version): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => $version, 'features' => 0]);

        try {
            $this->expectException(RequestException::class);
            $this->expectExceptionMessage('HTTPS proxies are not supported by the installed libcurl; libcurl 7.52.0 or newer built with HTTPS-proxy support is required.');

            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => 'https://proxy.example.com:3128',
            ]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public static function rejectedHttpsProxyOptionProvider(): array
    {
        return [
            ['HTTPS://proxy.example.com:3128'],
            [['https' => 'https://proxy.example.com:3128']],
        ];
    }

    /**
     * @dataProvider rejectedHttpsProxyOptionProvider
     *
     * @param string|array $proxy
     */
    public function testRejectsHttpsProxyFormsWhenLibcurlLacksSupport($proxy): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.50.0', 'features' => 0]);

        try {
            $this->expectException(RequestException::class);
            $this->expectExceptionMessage('HTTPS proxies are not supported by the installed libcurl');

            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), ['proxy' => $proxy]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public static function malformedProxyUrlProvider(): array
    {
        return [
            [' https://proxy.example.com:3128'],        // leading space before the scheme
            ["\u{00A0}https://proxy.example.com:3128"], // leading non-breaking space
            ['ht tps://proxy.example.com:3128'],        // space inside the scheme prefix
        ];
    }

    /**
     * @dataProvider malformedProxyUrlProvider
     */
    public function testRejectsMalformedProxyUrls(string $proxy): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The proxy URL is malformed.');

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'https://example.com'), ['proxy' => $proxy]);
    }

    public static function rejectedHttpsProxyEnvironmentProvider(): array
    {
        return [
            [['https_proxy' => 'https://proxy.example.com:3128']],
            [['HTTPS_PROXY' => 'https://proxy.example.com:3128']],
            [['all_proxy' => 'https://proxy.example.com:3128']],
            [['ALL_PROXY' => 'https://proxy.example.com:3128']],
        ];
    }

    /**
     * @dataProvider rejectedHttpsProxyEnvironmentProvider
     */
    public function testRejectsEnvironmentHttpsProxyWhenLibcurlLacksSupport(array $env): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('HTTPS proxies are not supported by the installed libcurl');

        self::withProxyEnvironment($env, static function (): void {
            $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.50.0', 'features' => 0]);

            try {
                $f = new CurlFactory(3);
                $f->create(new Psr7\Request('GET', 'https://example.com'), []);
            } finally {
                self::setCurlVersionInfo($previousVersionInfo);
            }
        });
    }

    public static function unaffectedProxyOptionProvider(): array
    {
        return [
            ['http://proxy.example.com:3128'],
            ['127.0.0.1:8125'],
            ['socks5://proxy.example.com:1080'],
            [''],
        ];
    }

    /**
     * @dataProvider unaffectedProxyOptionProvider
     */
    public function testDoesNotRejectNonHttpsProxiesOnOldLibcurl(string $proxy): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.50.0', 'features' => 0]);

        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), ['proxy' => $proxy]);

            self::assertSame($proxy, $_SERVER['_curl'][\CURLOPT_PROXY]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testAllowsHttpsProxyWhenLibcurlSupportsIt(): void
    {
        $httpsProxyFeature = \defined('CURL_VERSION_HTTPS_PROXY') ? \CURL_VERSION_HTTPS_PROXY : (1 << 21);
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.52.0', 'features' => $httpsProxyFeature]);

        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => 'https://proxy.example.com:3128',
            ]);

            self::assertSame('https://proxy.example.com:3128', $_SERVER['_curl'][\CURLOPT_PROXY]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testDoesNotRejectBypassedHttpsProxyOnOldLibcurl(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.50.0', 'features' => 0]);

        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => [
                    'https' => 'https://proxy.example.com:3128',
                    'no' => ['example.com'],
                ],
            ]);

            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testDoesNotRejectBypassedEnvironmentHttpsProxyOnOldLibcurl(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => 'https://proxy.example.com:3128',
            'NO_PROXY' => 'example.com',
        ], static function (): void {
            $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.50.0', 'features' => 0]);

            try {
                $f = new CurlFactory(3);
                $f->create(new Psr7\Request('GET', 'https://example.com'), []);

                self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
                self::assertNoProxyOption('*');
            } finally {
                self::setCurlVersionInfo($previousVersionInfo);
            }
        });
    }

    public function testRedactsProxyCredentialsInCurlErrorMessages(): void
    {
        $handler = new Handler\CurlHandler();

        try {
            $handler(new Psr7\Request('GET', Server::$url), [
                'proxy' => 'foo://user:secret@127.0.0.1:1',
            ])->wait();
            self::fail('Expected a transfer exception');
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            if (\strpos($e->getMessage(), 'foo://') !== false) {
                self::assertStringContainsString('foo://user:***@127.0.0.1:1', $e->getMessage());
            }
        }
    }

    public function testRedactsProxyCredentialsWhenProxyDefeatsUrlParsing(): void
    {
        $handler = new Handler\CurlHandler();

        try {
            $handler(new Psr7\Request('GET', Server::$url), [
                'proxy' => 'http://user:secret@127.0.0.1:99999999',
                'connect_timeout' => 1,
            ])->wait();
            self::fail('Expected a transfer exception');
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            if (\strpos($e->getMessage(), '127.0.0.1:99999999') !== false) {
                self::assertStringContainsString('***@127.0.0.1:99999999', $e->getMessage());
            }
        }
    }

    public function testRedactsEnvironmentProxyCredentialsInCurlErrorMessages(): void
    {
        self::withProxyEnvironment(['http_proxy' => 'foo://user:secret@127.0.0.1:1'], static function (): void {
            $handler = new Handler\CurlHandler();

            try {
                $handler(new Psr7\Request('GET', Server::$url), [])->wait();
                self::fail('Expected a transfer exception');
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                self::assertStringNotContainsString('secret', $e->getMessage());
            }
        });
    }

    public function testRedactsParseableProxyCredentialsIndependentlyOfCurlErrorText(): void
    {
        $proxy = 'http://user:secret@proxy.example.com:8125';

        $redacted = self::redactProxyUserInfo('Failed to connect via '.$proxy, $proxy);

        self::assertStringNotContainsString('secret', $redacted);
        self::assertSame('Failed to connect via http://user:***@proxy.example.com:8125', $redacted);
    }

    public function testRedactsProxyCredentialsContainingRawControlBytes(): void
    {
        $proxy = "http://user:se\x01cr\x7Fet@proxy.example.com:8125";

        $redacted = self::redactProxyUserInfo('Failed to connect via '.$proxy, $proxy);

        self::assertStringNotContainsString("se\x01cr\x7Fet", $redacted);
        self::assertSame('Failed to connect via http://user:***@proxy.example.com:8125', $redacted);
    }

    public function testRedactsUnparsableProxyCredentialsIndependentlyOfCurlErrorText(): void
    {
        $proxy = 'http://user:secret@127.0.0.1:99999999';

        $redacted = self::redactProxyUserInfo("Unsupported proxy syntax in '".$proxy."'", $proxy);

        self::assertStringNotContainsString('secret', $redacted);
        self::assertSame("Unsupported proxy syntax in 'http://***@127.0.0.1:99999999'", $redacted);
    }

    /**
     * @dataProvider proxyCredentialSeparatorProvider
     */
    public function testRedactsProxyCredentialsContainingRawSeparators(string $proxy): void
    {
        $redacted = self::redactProxyUserInfo("Unsupported proxy syntax in '".$proxy."'", $proxy);

        self::assertStringNotContainsString('cret', $redacted);
        self::assertSame("Unsupported proxy syntax in 'http://***@proxy.example.com:8125'", $redacted);
    }

    public static function proxyCredentialSeparatorProvider(): iterable
    {
        yield 'slash in password' => ['http://user:se/cret@proxy.example.com:8125'];
        yield 'question mark in password' => ['http://user:se?cret@proxy.example.com:8125'];
        yield 'hash in password' => ['http://user:se#cret@proxy.example.com:8125'];
    }

    public function testLeavesCurlErrorsUntouchedForParseableProxiesWithAtInPath(): void
    {
        $proxy = 'http://proxy.example.com:8125/health@check';
        $error = "Failed to connect via '".$proxy."'";

        self::assertSame($error, self::redactProxyUserInfo($error, $proxy));
    }

    public function testLeavesCurlErrorsUntouchedForProxiesWithoutCredentials(): void
    {
        $error = 'Failed to connect to proxy.example.com:8125';

        self::assertSame($error, self::redactProxyUserInfo($error, 'http://proxy.example.com:8125'));
    }

    /**
     * @dataProvider proxyTunnelSectionProvider
     */
    public function testComputesProxyTunnelSignatureByChannel(string $version, string $uri, array $options, bool $sectioned): void
    {
        $factory = new CurlFactory(3);
        $easy = self::createOnFactory($factory, $version, $uri, $options);

        self::assertSame($sectioned, $easy->proxyTunnelSignature !== null);
        // The sectioned path never sets FRESH_CONNECT/FORBID_REUSE on a single
        // create; isolation is achieved by pool ownership, not per-handle.
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public static function proxyTunnelSectionProvider(): array
    {
        $cases = [
            'auth https proxy, affected curl' => ['8.19.0', 'https://example.com', ['proxy' => 'http://username:password@proxy.example.com:8080'], true],
            'auth https proxy, fixed curl' => ['8.20.0', 'https://example.com', ['proxy' => 'http://username:password@proxy.example.com:8080'], true],
            'curl proxy credentials, affected curl' => ['8.19.0', 'https://example.com', ['proxy' => 'http://proxy.example.com:8080', 'curl' => [\CURLOPT_PROXYUSERPWD => 'username:password']], true],
            'curl proxy credentials, fixed curl' => ['8.20.0', 'https://example.com', ['proxy' => 'http://proxy.example.com:8080', 'curl' => [\CURLOPT_PROXYUSERPWD => 'username:password']], true],
            'anonymous tunnel, affected curl' => ['8.19.0', 'https://example.com', ['proxy' => 'http://proxy.example.com:8080'], true],
            'anonymous tunnel, fixed curl' => ['8.20.0', 'https://example.com', ['proxy' => 'http://proxy.example.com:8080'], true],
            'plain http proxy request' => ['8.19.0', 'http://example.com', ['proxy' => 'http://username:password@proxy.example.com:8080'], false],
            'socks proxy' => ['8.19.0', 'https://example.com', ['proxy' => 'socks5://username:password@proxy.example.com:1080'], false],
            'auth socks proxy, affected curl' => ['7.68.0', 'https://example.com', ['proxy' => 'socks5://username:password@proxy.example.com:1080'], true],
            'auth socks proxy, fixed curl' => ['7.69.0', 'https://example.com', ['proxy' => 'socks5://username:password@proxy.example.com:1080'], false],
            'anonymous socks proxy, affected curl' => ['7.68.0', 'https://example.com', ['proxy' => 'socks5://proxy.example.com:1080'], true],
            'http target socks proxy, affected curl' => ['7.68.0', 'http://example.com', ['proxy' => 'socks5://username:password@proxy.example.com:1080'], true],
            'auth socks4a proxy, affected curl' => ['7.68.0', 'https://example.com', ['proxy' => 'socks4a://username:password@proxy.example.com:1080'], true],
            'auth socks5h proxy, affected curl' => ['7.68.0', 'https://example.com', ['proxy' => 'socks5h://username:password@proxy.example.com:1080'], true],
            'socks4 proxy, affected curl' => ['7.68.0', 'https://example.com', ['proxy' => 'socks4://proxy.example.com:1080'], true],
            'no-proxy match' => ['8.19.0', 'https://example.com', ['proxy' => ['https' => 'http://username:password@proxy.example.com:8080', 'no' => ['example.com']]], false],
        ];

        // CONNECT_TO makes an HTTP proxy tunnel even an http:// target, so it
        // must section like any other authenticated tunnel.
        if (\defined('CURLOPT_CONNECT_TO')) {
            $connectTo = (int) \constant('CURLOPT_CONNECT_TO');
            $entry = ['example.com:80:backend.example.com:80'];
            $cases += [
                'connect-to http tunnel, auth proxy url, affected curl' => ['8.19.0', 'http://example.com', ['proxy' => 'http://username:password@proxy.example.com:8080', 'curl' => [$connectTo => $entry]], true],
                'connect-to http tunnel, curl credentials, affected curl' => ['8.19.0', 'http://example.com', ['proxy' => 'http://proxy.example.com:8080', 'curl' => [$connectTo => $entry, \CURLOPT_PROXYUSERPWD => 'username:password']], true],
                'connect-to http tunnel, anonymous, affected curl' => ['8.19.0', 'http://example.com', ['proxy' => 'http://proxy.example.com:8080', 'curl' => [$connectTo => $entry]], true],
                'connect-to http tunnel, fixed curl' => ['8.20.0', 'http://example.com', ['proxy' => 'http://username:password@proxy.example.com:8080', 'curl' => [$connectTo => $entry]], true],
                'connect-to socks proxy stays out' => ['8.19.0', 'http://example.com', ['proxy' => 'socks5://username:password@proxy.example.com:1080', 'curl' => [$connectTo => $entry]], false],
            ];
        }

        return $cases;
    }

    public function testSocksProxyCredentialsChangeSocksProxySignatureOnAffectedCurlVersion(): void
    {
        $factory = new CurlFactory(3);
        $userOne = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://username:one@proxy.example.com:1080',
        ])->proxyTunnelSignature;
        $userTwo = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://username:two@proxy.example.com:1080',
        ])->proxyTunnelSignature;
        $anonymous = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://proxy.example.com:1080',
        ])->proxyTunnelSignature;
        $stable = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://username:one@proxy.example.com:1080',
        ])->proxyTunnelSignature;

        self::assertNotNull($userOne);
        self::assertNotNull($userTwo);
        self::assertNotNull($anonymous);
        self::assertNotSame($userOne, $userTwo);
        self::assertNotSame($userOne, $anonymous);
        self::assertSame($userOne, $stable);
    }

    public function testCurlSocksProxyCredentialsChangeSocksProxySignatureOnAffectedCurlVersion(): void
    {
        $factory = new CurlFactory(3);
        $anonymous = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://proxy.example.com:1080',
        ])->proxyTunnelSignature;
        $credentialed = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://proxy.example.com:1080',
            'curl' => [\CURLOPT_PROXYUSERPWD => 'username:password'],
        ])->proxyTunnelSignature;

        self::assertNotNull($credentialed);
        self::assertNotSame($anonymous, $credentialed);
    }

    public function testSchemelessSocksProxySectionsByProxyTypeOnAffectedCurlVersion(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.68.0', 'features' => self::curlSslFeature()]);

        try {
            $method = new \ReflectionMethod(CurlFactory::class, 'proxyTunnelSignature');
            if (\PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }

            self::assertNotNull($method->invoke(null, new Psr7\Request('GET', 'https://example.com'), [
                \CURLOPT_PROXY => 'proxy.example.com:1080',
                \CURLOPT_PROXYTYPE => \CURLPROXY_SOCKS5,
            ]));
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testHttpSchemeSocksProxyTypeSectionsAsSocksOnAffectedCurlVersion(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.68.0', 'features' => self::curlSslFeature()]);

        try {
            $method = new \ReflectionMethod(CurlFactory::class, 'proxyTunnelSignature');
            if (\PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }

            self::assertNotNull($method->invoke(null, new Psr7\Request('GET', 'http://example.com'), [
                \CURLOPT_PROXY => 'http://proxy.example.com:1080',
                \CURLOPT_PROXYTYPE => \CURLPROXY_SOCKS5,
            ]));
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testRawCurlNoProxyWildcardDisablesEffectiveProxy(): void
    {
        self::skipIfCurlNoProxyIsUnavailable();

        self::assertNull(self::getEffectiveProxy([
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            (int) \constant('CURLOPT_NOPROXY') => '*',
        ]));
    }

    /**
     * @dataProvider nonWildcardRawCurlNoProxyProvider
     */
    public function testRawCurlNoProxyWildcardMustMatchExactly(string $noProxy): void
    {
        self::skipIfCurlNoProxyIsUnavailable();

        self::assertSame('http://proxy.example.com:8080', self::getEffectiveProxy([
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            (int) \constant('CURLOPT_NOPROXY') => $noProxy,
        ]));
    }

    public static function nonWildcardRawCurlNoProxyProvider(): array
    {
        return [
            'space padded wildcard' => [' * '],
            'tab padded wildcard' => ["\t*\t"],
            'nul-prefixed wildcard' => ["\0*"],
            'host pattern' => ['example.com'],
        ];
    }

    public function testEffectiveProxyWithoutRawCurlNoProxyIsUnchanged(): void
    {
        self::assertSame('http://proxy.example.com:8080', self::getEffectiveProxy([
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
        ]));
    }

    public function testSectionsProxyAuthorizationHeaderEvenOnFixedCurlVersion(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        $factory = new CurlFactory(3);
        $easy = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
            ],
        ]);

        // libcurl cannot key connection reuse on a literal Proxy-Authorization
        // header, so Guzzle sections it even on the fast-path version.
        self::assertNotNull($easy->proxyTunnelSignature);
    }

    public function testSectionsConnectToProxyAuthorizationHeaderEvenOnFixedCurlVersion(): void
    {
        if (!\defined('CURLOPT_CONNECT_TO')) {
            self::markTestSkipped('CURLOPT_CONNECT_TO is not available.');
        }

        $proxyHeaderOption = self::proxyHeaderOption();

        $factory = new CurlFactory(3);
        $easy = self::createOnFactory($factory, '8.20.0', 'http://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                (int) \constant('CURLOPT_CONNECT_TO') => ['example.com:80:backend.example.com:80'],
                $proxyHeaderOption => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
            ],
        ]);

        // CONNECT_TO tunnels the http:// request, and libcurl cannot key reuse
        // on a literal Proxy-Authorization header, so it sections even on fixed
        // libcurl.
        self::assertNotNull($easy->proxyTunnelSignature);
    }

    public function testEmptyProxyAuthorizationHeaderUsesDelegatedOwnerOnFixedCurlVersion(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        $factory = new CurlFactory(3);
        $delegatedBaseline = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
        ])->proxyTunnelSignature;
        $easy = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['Proxy-Authorization:'],
            ],
        ]);

        self::assertNotNull($easy->proxyTunnelSignature);
        self::assertSame($delegatedBaseline, $easy->proxyTunnelSignature);
    }

    public function testUnrelatedProxyHeaderUsesDelegatedOwnerOnFixedCurlVersion(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        $factory = new CurlFactory(3);
        $delegatedBaseline = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
        ])->proxyTunnelSignature;
        $easy = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['X-Proxy-Header: value'],
            ],
        ]);

        self::assertNotNull($easy->proxyTunnelSignature);
        self::assertSame($delegatedBaseline, $easy->proxyTunnelSignature);
    }

    public function testDelegatedProxyTunnelOwnerIsDistinctFromLiteralProxyAuthorizationOwner(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        $factory = new CurlFactory(3);
        $delegated = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
        ])->proxyTunnelSignature;
        $anonymousDelegated = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
        ])->proxyTunnelSignature;
        $literalHeader = self::createOnFactory($factory, '8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
            ],
        ])->proxyTunnelSignature;

        self::assertNotNull($delegated);
        self::assertSame($delegated, $anonymousDelegated);
        self::assertNotSame($delegated, $literalHeader);
    }

    public function testMigratesPsrProxyAuthorizationHeaderToProxyHeaderWhenSupported(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        self::skipIfProxyHeaderSeparationUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.37.0', 'features' => 0]);

        try {
            (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'http://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcm5hbWU6cGFzc3dvcmQ=',
                ]),
                ['proxy' => 'http://proxy.example.com:8080']
            );

            self::assertNotContains('Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
            self::assertContains('Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=', $_SERVER['_curl'][$proxyHeaderOption]);
            self::assertSame((int) \constant('CURLHEADER_SEPARATE'), $_SERVER['_curl'][(int) \constant('CURLOPT_HEADEROPT')]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMigratedPsrProxyAuthorizationHeaderSectionsTunnelEvenOnFixedCurl(): void
    {
        self::skipIfProxyHeaderSeparationUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '8.20.0', 'features' => 0]);

        try {
            $factory = new CurlFactory(3);
            $first = $factory->create(
                new Psr7\Request('GET', 'https://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcjE6cGFzczE=',
                ]),
                ['proxy' => 'http://proxy.example.com:8080']
            );
            $second = $factory->create(
                new Psr7\Request('GET', 'https://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcjI6cGFzczI=',
                ]),
                ['proxy' => 'http://proxy.example.com:8080']
            );

            self::assertNotNull($first->proxyTunnelSignature);
            self::assertNotNull($second->proxyTunnelSignature);
            self::assertNotSame($first->proxyTunnelSignature, $second->proxyTunnelSignature);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMigratedProxyAuthorizationAppendsToExistingProxyHeaders(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        self::skipIfProxyHeaderSeparationUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.42.1', 'features' => 0]);

        try {
            (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'http://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcm5hbWU6cGFzc3dvcmQ=',
                ]),
                [
                    'proxy' => 'http://proxy.example.com:8080',
                    'curl' => [$proxyHeaderOption => ['X-Proxy-Trace: 1']],
                ]
            );

            self::assertSame(
                ['X-Proxy-Trace: 1', 'Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
                $_SERVER['_curl'][$proxyHeaderOption]
            );
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testProxyHeaderSeparationIsSetWhenProxyHeaderAlreadyPresent(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        self::skipIfProxyHeaderSeparationUnavailable();

        $factory = new CurlFactory(3);
        self::createOnFactory($factory, '7.42.1', 'http://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [$proxyHeaderOption => ['X-Proxy-Trace: 1']],
        ]);

        self::assertSame((int) \constant('CURLHEADER_SEPARATE'), $_SERVER['_curl'][(int) \constant('CURLOPT_HEADEROPT')]);
    }

    public function testProxyHeaderSeparationIsSetForConnectTunnelWithoutProxyHeader(): void
    {
        self::skipIfProxyHeaderSeparationUnavailable();

        $factory = new CurlFactory(3);
        self::createOnFactory($factory, '7.37.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
        ]);

        self::assertSame((int) \constant('CURLHEADER_SEPARATE'), $_SERVER['_curl'][(int) \constant('CURLOPT_HEADEROPT')]);
    }

    public function testLegacyCurlPreservesProxyAuthorizationHeaderAndForcesFreshConnection(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.36.0', 'features' => 0]);

        try {
            (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'http://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcm5hbWU6cGFzc3dvcmQ=',
                ]),
                ['proxy' => 'http://proxy.example.com:8080']
            );

            self::assertContains('Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
            self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
            self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testDirectRequestDoesNotMoveProxyAuthorizationHeader(): void
    {
        self::withProxyEnvironment([], static function (): void {
            $previousVersionInfo = self::setCurlVersionInfo(['version' => '8.20.0', 'features' => 0]);

            try {
                (new CurlFactory(3))->create(
                    new Psr7\Request('GET', 'http://example.com', [
                        'Proxy-Authorization' => 'Basic dXNlcm5hbWU6cGFzc3dvcmQ=',
                    ]),
                    ['proxy' => '']
                );

                self::assertContains('Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
                self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
                self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
                if (\defined('CURLOPT_PROXYHEADER')) {
                    self::assertArrayNotHasKey((int) \constant('CURLOPT_PROXYHEADER'), $_SERVER['_curl']);
                }
                if (\defined('CURLOPT_HEADEROPT')) {
                    self::assertArrayNotHasKey((int) \constant('CURLOPT_HEADEROPT'), $_SERVER['_curl']);
                }
            } finally {
                self::setCurlVersionInfo($previousVersionInfo);
            }
        });
    }

    public function testSocksProxyDoesNotMoveProxyAuthorizationHeader(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '8.20.0', 'features' => 0]);

        try {
            (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'http://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcm5hbWU6cGFzc3dvcmQ=',
                ]),
                ['proxy' => 'socks5://proxy.example.com:1080']
            );

            self::assertContains('Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
            if (\defined('CURLOPT_PROXYHEADER')) {
                self::assertArrayNotHasKey((int) \constant('CURLOPT_PROXYHEADER'), $_SERVER['_curl']);
            }
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testNonArrayProxyHeaderThrowsWhenMigrationWouldAppend(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        self::skipIfProxyHeaderSeparationUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.42.1', 'features' => 0]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CURLOPT_PROXYHEADER');

            (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'http://example.com', [
                    'Proxy-Authorization' => 'Basic dXNlcm5hbWU6cGFzc3dvcmQ=',
                ]),
                [
                    'proxy' => 'http://proxy.example.com:8080',
                    'curl' => [$proxyHeaderOption => 'not-an-array'],
                ]
            );
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMigratesEmptyPsrProxyAuthorizationHeaderWithoutTreatingItAsCredential(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        self::skipIfProxyHeaderSeparationUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '8.20.0', 'features' => 0]);

        try {
            $easy = (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'https://example.com', ['Proxy-Authorization' => '']),
                ['proxy' => 'http://proxy.example.com:8080']
            );

            self::assertNotContains('Proxy-Authorization;', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
            self::assertContains('Proxy-Authorization;', $_SERVER['_curl'][$proxyHeaderOption]);
            self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
            self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);

            $baseline = self::createOnFactory(new CurlFactory(3), '8.20.0', 'https://example.com', [
                'proxy' => 'http://proxy.example.com:8080',
            ]);
            self::assertSame($baseline->proxyTunnelSignature, $easy->proxyTunnelSignature);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testLegacyCurlEmptyProxyAuthorizationHeaderDoesNotForceFreshConnection(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.36.0', 'features' => 0]);

        try {
            (new CurlFactory(3))->create(
                new Psr7\Request('GET', 'https://example.com', ['Proxy-Authorization' => '']),
                ['proxy' => 'http://proxy.example.com:8080']
            );

            self::assertContains('Proxy-Authorization;', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
            self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
            self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMigratesRawHttpHeaderProxyAuthorizationWhenSupportedUsingHelper(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();
        self::skipIfProxyHeaderSeparationUnavailable();

        $conf = self::invokeProxyAuthorizationHeaderHandling('7.37.0', new Psr7\Request('GET', 'http://example.com'), [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            \CURLOPT_HTTPHEADER => ['Accept:', 'Proxy-Authorization: Basic raw'],
        ]);

        self::assertSame(['Accept:'], $conf[\CURLOPT_HTTPHEADER]);
        self::assertContains('Proxy-Authorization: Basic raw', $conf[$proxyHeaderOption]);
        self::assertSame((int) \constant('CURLHEADER_SEPARATE'), $conf[(int) \constant('CURLOPT_HEADEROPT')]);
    }

    public function testLegacyCurlRawHttpHeaderProxyAuthorizationForcesFreshConnectionUsingHelper(): void
    {
        $conf = self::invokeProxyAuthorizationHeaderHandling('7.36.0', new Psr7\Request('GET', 'http://example.com'), [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            \CURLOPT_HTTPHEADER => ['Proxy-Authorization: Basic raw'],
        ]);

        self::assertContains('Proxy-Authorization: Basic raw', $conf[\CURLOPT_HTTPHEADER]);
        self::assertTrue($conf[\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($conf[\CURLOPT_FORBID_REUSE]);
    }

    public function testProxyAuthorizationHeaderOrderAffectsSignature(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        $first = self::computeProxyTunnelSignature('8.20.0', 'https://example.com', [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            $proxyHeaderOption => [
                'Proxy-Authorization: Basic dXNlcjE6cGFzczE=',
                'Proxy-Authorization: Basic dXNlcjI6cGFzczI=',
            ],
        ]);
        $second = self::computeProxyTunnelSignature('8.20.0', 'https://example.com', [
            \CURLOPT_PROXY => 'http://proxy.example.com:8080',
            $proxyHeaderOption => [
                'Proxy-Authorization: Basic dXNlcjI6cGFzczI=',
                'Proxy-Authorization: Basic dXNlcjE6cGFzczE=',
            ],
        ]);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
    }

    public function testSectionsAuthenticatedHttpProxyTunnelOnAffectedCurlVersion(): void
    {
        if (!\defined('CURLOPT_HTTPPROXYTUNNEL')) {
            self::markTestSkipped('CURLOPT_HTTPPROXYTUNNEL is not available.');
        }

        $factory = new CurlFactory(3);
        $easy = self::createOnFactory($factory, '8.19.0', 'http://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
            'curl' => [
                \CURLOPT_HTTPPROXYTUNNEL => true,
            ],
        ]);

        self::assertNotNull($easy->proxyTunnelSignature);
    }

    public function testReusesIdleHandleForSameProxyTunnelSignature(): void
    {
        $factory = new CurlFactory(3);
        $options = ['proxy' => 'http://username:password@proxy.example.com:8080'];

        $first = self::createOnFactory($factory, '8.19.0', 'https://example.com', $options);
        $pooled = $first->handle;
        $factory->release($first);

        $second = self::createOnFactory($factory, '8.19.0', 'https://example.com', $options);

        self::assertSame($pooled, $second->handle);
    }

    public function testProxyTlsAuthCredentialChangesProxyTunnelSignature(): void
    {
        if (!\defined('CURLOPT_PROXY_TLSAUTH_PASSWORD')) {
            self::markTestSkipped('CURLOPT_PROXY_TLSAUTH_PASSWORD is not available.');
        }

        // TLS-SRP is a proxy credential that libcurl did not key connection
        // reuse on before 7.83.1 (CVE-2022-27782), so on an affected version it
        // must change the signature for the proxy tunnel to be sectioned.
        $tlsAuthPassword = (int) \constant('CURLOPT_PROXY_TLSAUTH_PASSWORD');

        $first = self::computeProxyTunnelSignature('8.19.0', 'https://example.com', [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080',
            $tlsAuthPassword => 'secret1',
        ]);
        $second = self::computeProxyTunnelSignature('8.19.0', 'https://example.com', [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080',
            $tlsAuthPassword => 'secret2',
        ]);

        self::assertNotNull($first);
        self::assertNotSame($first, $second);
    }

    public function testProxySslKeyChangesProxyTunnelSignature(): void
    {
        if (!\defined('CURLOPT_PROXY_SSLKEY')) {
            self::markTestSkipped('CURLOPT_PROXY_SSLKEY is not available.');
        }

        // On this non-delegated (pre-8.20.0) path the proxy private-key file is
        // keyed as fallback hardening: libcurl's mTLS private-key matching on reuse
        // was incomplete before 8.21.0 (CVE-2026-8932).
        $sslKey = (int) \constant('CURLOPT_PROXY_SSLKEY');
        $first = self::computeProxyTunnelSignature('8.19.0', 'https://example.com', [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080', $sslKey => '/path/to/key-a.pem',
        ]);
        $second = self::computeProxyTunnelSignature('8.19.0', 'https://example.com', [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080', $sslKey => '/path/to/key-b.pem',
        ]);

        self::assertNotNull($first);
        self::assertNotSame($first, $second);
    }

    public function testProxyKeyPasswdChangesProxyTunnelSignature(): void
    {
        if (!\defined('CURLOPT_PROXY_KEYPASSWD')) {
            self::markTestSkipped('CURLOPT_PROXY_KEYPASSWD is not available.');
        }

        $keyPasswd = (int) \constant('CURLOPT_PROXY_KEYPASSWD');
        $first = self::computeProxyTunnelSignature('8.19.0', 'https://example.com', [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080', $keyPasswd => 'secret-a',
        ]);
        $second = self::computeProxyTunnelSignature('8.19.0', 'https://example.com', [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080', $keyPasswd => 'secret-b',
        ]);

        self::assertNotNull($first);
        self::assertNotSame($first, $second);
    }

    public function testProxyTlsCredentialsRequireFreshConnectionOnAffectedCurlVersion(): void
    {
        if (!\defined('CURLOPT_PROXY_TLSAUTH_PASSWORD')) {
            self::markTestSkipped('CURLOPT_PROXY_TLSAUTH_PASSWORD is not available.');
        }

        // Under a configured share handle the signature path is bypassed for
        // the force-fresh path. libcurl did not key connection reuse on TLS-SRP
        // before 7.83.1 (CVE-2022-27782), so that path must force a fresh
        // tunnel on an affected version and need not from 7.83.1 onwards.
        $conf = [
            \CURLOPT_PROXY => 'https://proxy.example.com:8080',
            (int) \constant('CURLOPT_PROXY_TLSAUTH_PASSWORD') => 'secret',
        ];

        self::assertTrue(self::computeRequiresFreshForAuthenticatedProxy('7.79.0', 'https://example.com', $conf));
        self::assertFalse(self::computeRequiresFreshForAuthenticatedProxy('7.83.1', 'https://example.com', $conf));
    }

    public function testFirstProxyTunnelOwnerReusesPooledHandleWithoutPurging(): void
    {
        $factory = new CurlFactory(3);

        // Pool a direct (null-signature) handle.
        $direct = self::createOnFactory($factory, '8.19.0', 'https://example.com', []);
        $pooled = $direct->handle;
        $factory->release($direct);

        // The first in-domain request latches the owner without purging, so
        // the pooled handle is reused rather than discarded.
        $tunnel = self::createOnFactory($factory, '8.19.0', 'https://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
        ]);

        self::assertSame($pooled, $tunnel->handle);
    }

    public function testPurgesIdleHandlesWhenProxyTunnelOwnerChanges(): void
    {
        $factory = new CurlFactory(3);

        $first = self::createOnFactory($factory, '8.19.0', 'https://example.com', [
            'proxy' => 'http://user1:pass1@proxy.example.com:8080',
        ]);
        $factory->release($first);
        self::assertCount(1, self::readIdleHandles($factory));

        // A different owner purges the idle pool before the new handle is
        // popped, so the foreign tunnel handle does not survive.
        $second = self::createOnFactory($factory, '8.19.0', 'https://example.com', [
            'proxy' => 'http://user2:pass2@proxy.example.com:8080',
        ]);
        self::assertCount(0, self::readIdleHandles($factory));

        $factory->release($second);
        self::assertCount(1, self::readIdleHandles($factory));
    }

    public function testReleaseDropsHandleFromSupersededOwner(): void
    {
        $factory = new CurlFactory(3);

        $a = self::createOnFactory($factory, '8.19.0', 'https://example.com', [
            'proxy' => 'http://a:a@proxy.example.com:8080',
        ]);
        // A second owner supersedes the first while the first is still in flight.
        $b = self::createOnFactory($factory, '8.19.0', 'https://example.com', [
            'proxy' => 'http://b:b@proxy.example.com:8080',
        ]);

        $factory->release($a);
        self::assertCount(0, self::readIdleHandles($factory));

        $factory->release($b);
        self::assertCount(1, self::readIdleHandles($factory));
    }

    public function testShareHandleUsesBlanketForceFreshForAuthenticatedProxy(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);
        $easy = self::createOnFactory($factory, '8.19.0', 'https://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
        ]);

        self::assertNull($easy->proxyTunnelSignature);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testShareHandleUsesBlanketForceFreshForAuthenticatedSocksProxyOnAffectedCurlVersion(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);
        $easy = self::createOnFactory($factory, '7.68.0', 'http://example.com', [
            'proxy' => 'socks5://username:password@proxy.example.com:1080',
        ]);

        self::assertNull($easy->proxyTunnelSignature);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testShareHandleSkipsBlanketForceFreshForAnonymousSocksProxyOnAffectedCurlVersion(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);
        $easy = self::createOnFactory($factory, '7.68.0', 'https://example.com', [
            'proxy' => 'socks5://proxy.example.com:1080',
        ]);

        self::assertNull($easy->proxyTunnelSignature);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testShareHandleSkipsBlanketForceFreshForSocksProxyOnFixedCurlVersion(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);
        $easy = self::createOnFactory($factory, '7.69.0', 'https://example.com', [
            'proxy' => 'socks5://username:password@proxy.example.com:1080',
        ]);

        self::assertNull($easy->proxyTunnelSignature);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    private function checkNoProxyForHost($url, $noProxy, $assertUseProxy)
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', $url), [
            'proxy' => [
                'http' => 'http://bar.com',
                'https' => 'https://t',
                'no' => $noProxy,
            ],
        ]);
        if ($assertUseProxy) {
            self::assertSame('http://bar.com', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('');
        } else {
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertNoProxyOption('*');
        }
    }

    public function testUsesProxy()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [
                'Foo' => 'Bar',
                'Baz' => 'bam',
                'Content-Length' => '2',
            ], 'hi'),
        ]);

        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', 'http://www.example.com', [], null, '1.0');
        $promise = $handler($request, [
            'proxy' => Server::$url,
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('2', $response->getHeaderLine('Content-Length'));
        self::assertSame('hi', (string) $response->getBody());
    }

    public function testValidatesCryptoMethodInvalidMethod()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method request option: unknown version provided');
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => 123]);
    }

    public function testAddsCryptoMethodTls10()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]);
        self::assertEquals(\CURL_SSLVERSION_TLSv1_0, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testAddsCryptoMethodTls11()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT]);
        self::assertEquals(\CURL_SSLVERSION_TLSv1_1, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testAddsCryptoMethodTls12()
    {
        $previous = self::setCurlVersionInfo(['version' => '7.34.0', 'features' => self::curlSslFeature()]);
        $f = new CurlFactory(3);

        try {
            $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
            self::assertEquals(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    /**
     * @requires PHP >= 7.4
     */
    public function testAddsCryptoMethodTls13()
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_3')) {
            self::markTestSkipped('CURL_SSLVERSION_TLSv1_3 is unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.52.0', 'features' => self::curlSslFeature()]);
        $f = new CurlFactory(3);

        try {
            $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]);
            self::assertEquals(\CURL_SSLVERSION_TLSv1_3, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testAddsCryptoMethodMaxTls12()
    {
        if (!\defined('CURL_SSLVERSION_MAX_TLSv1_2')) {
            self::markTestSkipped('CURL_SSLVERSION_MAX_TLSv1_2 is unavailable.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);

        self::assertEquals(
            \CURL_SSLVERSION_DEFAULT | \CURL_SSLVERSION_MAX_TLSv1_2,
            $_SERVER['_curl'][\CURLOPT_SSLVERSION]
        );
    }

    public function testAddsExactCryptoMethodTls12Range()
    {
        if (!\defined('CURL_SSLVERSION_MAX_TLSv1_2')) {
            self::markTestSkipped('CURL_SSLVERSION_MAX_TLSv1_2 is unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.34.0', 'features' => self::curlSslFeature()]);
        $f = new CurlFactory(3);

        try {
            $f->create(new Psr7\Request('GET', Server::$url), [
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ]);

            self::assertEquals(
                \CURL_SSLVERSION_TLSv1_2 | \CURL_SSLVERSION_MAX_TLSv1_2,
                $_SERVER['_curl'][\CURLOPT_SSLVERSION]
            );
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testRejectsCryptoMethodMaxLowerThanMin()
    {
        if (!\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            self::markTestSkipped('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT is unavailable.');
        }

        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('crypto_method_max');

        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);
    }

    public function testRejectsHttp2CryptoMethodMaxBelowTls12()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP/2 requires TLS 1.2 or higher');

        $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
        ]);
    }

    public function testRejectsTls12CryptoMethodWhenCurlLacksTls12()
    {
        $previous = self::setCurlVersionInfo(['version' => '7.34.0', 'features' => 0]);
        $f = new CurlFactory(3);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('TLS 1.2 not supported by your version of cURL');

            $f->create(new Psr7\Request('GET', Server::$url, [], null, '1.1'), [
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ]);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testPromotesHttp2WeakMinToTls12()
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2')) {
            self::markTestSkipped('CURL_SSLVERSION_TLSv1_2 is unavailable.');
        }

        $http2Feature = \defined('CURL_VERSION_HTTP2') ? \CURL_VERSION_HTTP2 : (1 << 16);
        $previous = self::setCurlVersionInfo([
            'version' => '7.52.0',
            'features' => self::curlSslFeature() | $http2Feature,
        ]);
        $f = new CurlFactory(3);

        try {
            $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
            ]);

            self::assertEquals(
                \CURL_SSLVERSION_TLSv1_2,
                $_SERVER['_curl'][\CURLOPT_SSLVERSION]
            );
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testRejectsCryptoMethodMaxUnknownInteger()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method_max request option: unknown version provided');

        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method_max' => 123,
        ]);
    }

    public function testRejectsNonIntCryptoMethodWithInvalidArgumentException()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown version provided');

        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method' => 'foo',
        ]);
    }

    public function testRejectsNonIntCryptoMethodMaxWithInvalidArgumentException()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown version provided');

        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method_max' => [],
        ]);
    }

    public function testAddsCryptoMethodMaxTls10()
    {
        if (!\defined('CURL_SSLVERSION_MAX_TLSv1_0')) {
            self::markTestSkipped('CURL_SSLVERSION_MAX_TLSv1_0 is unavailable.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
        ]);

        self::assertEquals(
            \CURL_SSLVERSION_DEFAULT | \CURL_SSLVERSION_MAX_TLSv1_0,
            $_SERVER['_curl'][\CURLOPT_SSLVERSION]
        );
    }

    public function testAddsCryptoMethodMaxTls11()
    {
        if (!\defined('CURL_SSLVERSION_MAX_TLSv1_1')) {
            self::markTestSkipped('CURL_SSLVERSION_MAX_TLSv1_1 is unavailable.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
        ]);

        self::assertEquals(
            \CURL_SSLVERSION_DEFAULT | \CURL_SSLVERSION_MAX_TLSv1_1,
            $_SERVER['_curl'][\CURLOPT_SSLVERSION]
        );
    }

    public function testAddsCryptoMethodMaxTls13()
    {
        if (!\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') || !\defined('CURL_SSLVERSION_MAX_TLSv1_3')) {
            self::markTestSkipped('TLS 1.3 maximum is unavailable.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]);

        self::assertEquals(
            \CURL_SSLVERSION_DEFAULT | \CURL_SSLVERSION_MAX_TLSv1_3,
            $_SERVER['_curl'][\CURLOPT_SSLVERSION]
        );
    }

    public function testAddsCryptoMethodMinTls10MaxTls12()
    {
        if (!\defined('CURL_SSLVERSION_MAX_TLSv1_2')) {
            self::markTestSkipped('CURL_SSLVERSION_MAX_TLSv1_2 is unavailable.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);

        self::assertEquals(
            \CURL_SSLVERSION_TLSv1_0 | \CURL_SSLVERSION_MAX_TLSv1_2,
            $_SERVER['_curl'][\CURLOPT_SSLVERSION]
        );
    }

    public function testValidatesSslKey()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL private key not found: /does/not/exist');
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => '/does/not/exist']);
    }

    public function testAddsSslKey()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLKEY]);
    }

    public function testAddsSslKeyWithPassword()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__, 'test']]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLKEY]);
        self::assertEquals('test', $_SERVER['_curl'][\CURLOPT_SSLKEYPASSWD]);
    }

    public function testAddsSslKeyWhenUsingArraySyntaxButNoPassword()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__]]);

        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLKEY]);
    }

    public function testAddsSslKeyType()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'ssl_key' => __FILE__,
            'ssl_key_type' => 'pem',
        ]);

        self::assertSame('PEM', $_SERVER['_curl'][\CURLOPT_SSLKEYTYPE]);
    }

    public function testAllowsEngineSslKeyIdentifiers()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'ssl_key' => 'engine-key-id',
            'ssl_key_type' => 'ENG',
        ]);

        self::assertSame('engine-key-id', $_SERVER['_curl'][\CURLOPT_SSLKEY]);
        self::assertSame('ENG', $_SERVER['_curl'][\CURLOPT_SSLKEYTYPE]);
    }

    /**
     * @dataProvider invalidSslKeyTypeProvider
     *
     * @param mixed $sslKeyType
     */
    public function testValidatesSslKeyType($sslKeyType)
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ssl_key_type must be a non-empty string');
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key_type' => $sslKeyType]);
    }

    public static function invalidSslKeyTypeProvider(): array
    {
        return [
            [[]],
            [''],
            [false],
        ];
    }

    /**
     * @dataProvider invalidSslKeyOptionProvider
     *
     * @param mixed $sslKey
     */
    public function testValidatesSslKeyOptionShape($sslKey)
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ssl_key request option');
        $f->create(new Psr7\Request('GET', 'http://example.com'), ['ssl_key' => $sslKey]);
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

    public function testValidatesCert()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL certificate not found: /does/not/exist');
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => '/does/not/exist']);
    }

    public function testAddsCert()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLCERT]);
    }

    public function testAddsCertWithPassword()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => [__FILE__, 'test']]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLCERT]);
        self::assertEquals('test', $_SERVER['_curl'][\CURLOPT_SSLCERTPASSWD]);
    }

    public function testAddsCertWithArrayPathOnly()
    {
        $f = new CurlFactory(3);
        $easy = $f->create(new Psr7\Request('GET', 'http://example.com'), ['cert' => [__FILE__]]);

        try {
            self::assertInstanceOf(EasyHandle::class, $easy);
        } finally {
            if (\PHP_VERSION_ID < 80000) {
                \curl_close($easy->handle);
            }
        }
    }

    public function testAddsCertType()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'cert' => __FILE__,
            'cert_type' => 'p12',
        ]);

        self::assertSame('P12', $_SERVER['_curl'][\CURLOPT_SSLCERTTYPE]);
    }

    /**
     * @dataProvider invalidCertTypeProvider
     *
     * @param mixed $certType
     */
    public function testValidatesCertType($certType)
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cert_type must be a non-empty string');
        $f->create(new Psr7\Request('GET', Server::$url), ['cert_type' => $certType]);
    }

    public static function invalidCertTypeProvider(): array
    {
        return [
            [[]],
            [''],
            [false],
        ];
    }

    /**
     * @dataProvider invalidCertOptionProvider
     *
     * @param mixed $cert
     */
    public function testValidatesCertOptionShape($cert)
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cert request option');
        $f->create(new Psr7\Request('GET', 'http://example.com'), ['cert' => $cert]);
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

    public function testAddsDerCert()
    {
        $certFile = tempnam(sys_get_temp_dir(), 'mock_test_cert');
        rename($certFile, $certFile .= '.der');
        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', Server::$url), ['cert' => $certFile]);
            self::assertArrayHasKey(\CURLOPT_SSLCERTTYPE, $_SERVER['_curl']);
            self::assertEquals('DER', $_SERVER['_curl'][\CURLOPT_SSLCERTTYPE]);
        } finally {
            @\unlink($certFile);
        }
    }

    public function testExplicitCertTypeOverridesCertExtension()
    {
        $certFile = tempnam(sys_get_temp_dir(), 'mock_test_cert');
        rename($certFile, $certFile .= '.der');
        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', Server::$url), [
                'cert' => $certFile,
                'cert_type' => 'PEM',
            ]);
            self::assertSame('PEM', $_SERVER['_curl'][\CURLOPT_SSLCERTTYPE]);
        } finally {
            @\unlink($certFile);
        }
    }

    public function testAddsP12Cert()
    {
        $certFile = tempnam(sys_get_temp_dir(), 'mock_test_cert');
        rename($certFile, $certFile .= '.p12');
        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', Server::$url), ['cert' => $certFile]);
            self::assertArrayHasKey(\CURLOPT_SSLCERTTYPE, $_SERVER['_curl']);
            self::assertEquals('P12', $_SERVER['_curl'][\CURLOPT_SSLCERTTYPE]);
        } finally {
            @\unlink($certFile);
        }
    }

    public function testValidatesProgress()
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('progress client option must be callable');
        $f->create(new Psr7\Request('GET', Server::$url), ['progress' => 'foo']);
    }

    public function testEmitsDebugInfoToStream()
    {
        $res = \fopen('php://temp', 'r+');
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), ['debug' => $res]);
        $response->wait();
        \rewind($res);
        $output = \str_replace("\r", '', \stream_get_contents($res));
        self::assertStringContainsString('> HEAD / HTTP/1.1', $output);
        self::assertStringContainsString('< HTTP/1.1 200', $output);
        \fclose($res);
    }

    public function testEmitsProgressToFunction()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $called = [];
        $request = new Psr7\Request('HEAD', Server::$url);
        $response = $a($request, [
            'progress' => static function (...$args) use (&$called) {
                $called[] = $args;
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
        $content = \gzencode('test');
        $headers = ['Content-Length' => (string) \strlen($content)];
        if ($withEncoding) {
            $headers['Content-Encoding'] = 'gzip';
        }
        $response = new Psr7\Response(200, $headers, $content);
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
        self::assertEquals('', $_SERVER['_curl'][\CURLOPT_ENCODING]);
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
            \strlen(\gzencode('test')),
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
        self::assertEquals('gzip', $_SERVER['_curl'][\CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        self::assertEquals('gzip', $sent->getHeaderLine('Accept-Encoding'));
        self::assertEquals('test', (string) $response->getBody());
        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertTrue(
            !$response->hasHeader('content-length')
            || $response->getHeaderLine('content-length') == $response->getBody()->getSize()
        );
    }

    public function testDecodesGzippedResponsesWithZeroHeader()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, ['Accept-Encoding' => '0']);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        self::assertEquals('0', $_SERVER['_curl'][\CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        self::assertEquals('0', $sent->getHeaderLine('Accept-Encoding'));
        self::assertEquals('test', (string) $response->getBody());
    }

    /**
     * https://github.com/guzzle/guzzle/issues/2799
     */
    public function testDecodesGzippedResponsesWithHeaderForHeadRequest()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('HEAD', Server::$url, ['Accept-Encoding' => 'gzip']);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        self::assertEquals('gzip', $_SERVER['_curl'][\CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        self::assertEquals('gzip', $sent->getHeaderLine('Accept-Encoding'));

        // Verify that the content-length matches the encoded size.
        self::assertTrue(
            !$response->hasHeader('content-length')
            || $response->getHeaderLine('content-length') == \strlen(\gzencode('test'))
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
        self::assertEquals(\CURL_HTTP_VERSION_1_0, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
    }

    public function testEmptyProtocolVersionDefaultsToHttp11()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, [], null, '');
        $a($request, []);
        self::assertEquals(\CURL_HTTP_VERSION_1_1, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
    }

    public function testMultiplexWaitSetsPipewaitForHttp2Requests()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $f = new CurlFactory(3);
        $easy = $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);

        try {
            self::assertSame(\CURL_HTTP_VERSION_2_0, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
            self::assertTrue($_SERVER['_curl'][\CURLOPT_PIPEWAIT]);
        } finally {
            $f->release($easy);
        }
    }

    public static function multiplexDisabledProvider(): iterable
    {
        yield 'option absent' => [[]];
        yield 'option eager' => [['multiplex' => Multiplexing::EAGER]];
    }

    /**
     * @dataProvider multiplexDisabledProvider
     */
    public function testMultiplexIsOffByDefaultForHttp2Requests(array $options)
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $f = new CurlFactory(3);
        $easy = $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), $options);

        try {
            self::assertSame(\CURL_HTTP_VERSION_2_0, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
            self::assertArrayNotHasKey(\CURLOPT_PIPEWAIT, $_SERVER['_curl']);
        } finally {
            $f->release($easy);
        }
    }

    public function testMultiplexIsIgnoredForHttp1Requests()
    {
        if (!\defined('CURLOPT_PIPEWAIT')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT is unavailable.');
        }

        $f = new CurlFactory(3);
        $easy = $f->create(new Psr7\Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::WAIT]);

        try {
            self::assertSame(\CURL_HTTP_VERSION_1_1, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
            self::assertArrayNotHasKey(\CURLOPT_PIPEWAIT, $_SERVER['_curl']);
        } finally {
            $f->release($easy);
        }
    }

    public function testMultiplexIsIgnoredWhenLibcurlDoesNotMultiplexByDefault()
    {
        if (!\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo([
            'version' => '7.61.1',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            $f = new CurlFactory(3);
            $easy = $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);

            try {
                self::assertArrayNotHasKey(\CURLOPT_PIPEWAIT, $_SERVER['_curl']);
            } finally {
                $f->release($easy);
            }
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    /**
     * @dataProvider invalidMultiplexProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidMultiplexValues($value)
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" option must be null or a GuzzleHttp\\Multiplexing::* constant');

        $f->create(new Psr7\Request('GET', Server::$url), ['multiplex' => $value]);
    }

    public static function invalidMultiplexProvider(): iterable
    {
        yield 'bool true' => [true];
        yield 'bool false' => [false];
        yield 'int' => [1];
        yield 'unknown string' => ['always'];
    }

    public static function requiredMultiplexProvider(): iterable
    {
        yield 'require_eager' => [Multiplexing::REQUIRE_EAGER];
        yield 'require_wait' => [Multiplexing::REQUIRE_WAIT];
    }

    public function testRequireWaitSetsPriorKnowledgeHttpVersion()
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            self::withProxyEnvironment([], static function (): void {
                $f = new CurlFactory(3);
                $easy = $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
                    'multiplex' => Multiplexing::REQUIRE_WAIT,
                ]);

                try {
                    self::assertSame((int) \constant('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE'), $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
                    self::assertTrue($_SERVER['_curl'][(int) \constant('CURLOPT_PIPEWAIT')]);
                } finally {
                    $f->release($easy);
                }
            });
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testRequireEagerSetsPriorKnowledgeWithoutPipewait()
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            self::withProxyEnvironment([], static function (): void {
                $f = new CurlFactory(3);
                $easy = $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
                    'multiplex' => Multiplexing::REQUIRE_EAGER,
                ]);

                try {
                    self::assertSame((int) \constant('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE'), $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
                    self::assertArrayNotHasKey((int) \constant('CURLOPT_PIPEWAIT'), $_SERVER['_curl']);
                } finally {
                    $f->release($easy);
                }
            });
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider requiredMultiplexProvider
     */
    public function testRequireRejectsHttp11Requests(string $multiplex)
    {
        $f = new CurlFactory(3);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be required for HTTP/1.1 requests; use protocol version 2.');

        $f->create(new Psr7\Request('GET', Server::$url, [], null, '1.1'), [
            'multiplex' => $multiplex,
        ]);
    }

    /**
     * @dataProvider requiredMultiplexProvider
     */
    public function testRequireRejectsUnsupportedLibcurl(string $multiplex)
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2') || !\defined('CURL_VERSION_HTTP2') || !\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('HTTP/2 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo([
            'version' => '8.13.0',
            'features' => \CURL_VERSION_HTTP2 | \CURL_VERSION_SSL,
        ]);

        try {
            $f = new CurlFactory(3);

            $this->expectException(ConnectException::class);
            $this->expectExceptionMessage('Required multiplexing needs libcurl 8.14.0 or newer built with HTTP/2 support.');

            $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
                'multiplex' => $multiplex,
            ]);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    /**
     * @dataProvider requiredMultiplexProvider
     */
    public function testRequireRejectsLibcurlWithoutHttp2(string $multiplex)
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2') || !\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('HTTP/2 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => \CURL_VERSION_SSL,
        ]);

        try {
            $f = new CurlFactory(3);

            $this->expectException(ConnectException::class);
            $this->expectExceptionMessage('Required multiplexing needs libcurl 8.14.0 or newer built with HTTP/2 support.');

            $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
                'multiplex' => $multiplex,
            ]);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    /**
     * @dataProvider requiredMultiplexProvider
     */
    public function testRequireRejectsCleartextProxiedRequests(string $multiplex)
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            self::withProxyEnvironment(['http_proxy' => 'http://proxy.example.com:8125'], function () use ($multiplex): void {
                $f = new CurlFactory(3);

                $this->expectException(ConnectException::class);
                $this->expectExceptionMessage('Required multiplexing cannot be guaranteed for cleartext requests sent through a proxy.');

                $f->create(new Psr7\Request('GET', Server::$url, [], null, '2.0'), [
                    'multiplex' => $multiplex,
                ]);
            });
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testDeprecatesRawPipewaitCurlOption()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        $deprecation = null;
        \set_error_handler(static function (int $severity, string $message) use (&$deprecation): bool {
            $deprecation = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $f = new CurlFactory(3);
            $easy = $f->create(new Psr7\Request('GET', Server::$url), [
                'curl' => [\CURLOPT_PIPEWAIT => 1],
            ]);
            $f->release($easy);
        } finally {
            \restore_error_handler();
        }

        self::assertNotNull($deprecation, 'Expected a deprecation for the raw CURLOPT_PIPEWAIT option.');
        self::assertStringContainsString('CURLOPT_PIPEWAIT', $deprecation);
        self::assertStringContainsString('multiplex', $deprecation);
    }

    public function testSavesToStream()
    {
        $stream = \fopen('php://memory', 'r+');
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink' => $stream,
        ]);
        $response->wait();
        \rewind($stream);
        self::assertEquals('test', \stream_get_contents($stream));
    }

    public function testSavesToGuzzleStream()
    {
        $stream = Psr7\Utils::streamFor();
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink' => $stream,
        ]);
        $response->wait();
        self::assertEquals('test', (string) $stream);
    }

    public function testSavesToFileOnDisk()
    {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'testfile');

        try {
            $this->addDecodeResponse();
            $handler = new Handler\CurlMultiHandler();
            $request = new Psr7\Request('GET', Server::$url);
            $response = $handler($request, [
                'decode_content' => true,
                'sink' => $tmpfile,
            ]);
            $response->wait();
            self::assertStringEqualsFile($tmpfile, 'test');
        } finally {
            @\unlink($tmpfile);
        }
    }

    public function testDoesNotAddMultipleContentLengthHeaders()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('PUT', Server::$url, ['Content-Length' => '3'], 'foo');
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

    public function testFailsWhenCannotRewindRetryAfterNoResponse()
    {
        $factory = new CurlFactory(1);
        $stream = Psr7\Utils::streamFor('abc');
        $stream->read(1);
        $stream = new Psr7\NoSeekStream($stream);
        $request = new Psr7\Request('PUT', Server::$url, [], $stream);
        $fn = static function ($request, $options) use (&$fn, $factory) {
            $easy = $factory->create($request, $options);

            return CurlFactory::finish($fn, $easy, $factory);
        };

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('but attempting to rewind the request body failed');
        $fn($request, [])->wait();
    }

    public function testRetriesWhenBodyCanBeRewound()
    {
        $callHandler = $called = false;

        $fn = static function ($r, $options) use (&$callHandler) {
            $callHandler = true;

            return P\Create::promiseFor(new Psr7\Response());
        };

        $bd = Psr7\FnStream::decorate(Psr7\Utils::streamFor('test'), [
            'tell' => static function () {
                return 1;
            },
            'rewind' => static function () use (&$called) {
                $called = true;
            },
        ]);

        $factory = new CurlFactory(1);
        $req = new Psr7\Request('PUT', Server::$url, [], $bd);
        $easy = $factory->create($req, []);
        $res = CurlFactory::finish($fn, $easy, $factory);
        $res = $res->wait();
        self::assertTrue($callHandler);
        self::assertTrue($called);
        self::assertEquals('200', $res->getStatusCode());
    }

    public function testFailsWhenRetryMoreThanThreeTimes()
    {
        $factory = new CurlFactory(1);
        $call = 0;
        $fn = static function ($request, $options) use (&$mock, &$call, $factory) {
            ++$call;
            $easy = $factory->create($request, $options);

            return CurlFactory::finish($mock, $easy, $factory);
        };
        $mock = new Handler\MockHandler([$fn, $fn, $fn]);
        $p = $mock(new Psr7\Request('PUT', Server::$url, [], 'test'), []);
        $p->wait(false);
        self::assertEquals(3, $call);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The cURL request was retried 3 times');
        $p->wait(true);
    }

    public function testHandles100Continue()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['Test' => 'Hello', 'Content-Length' => '4'], 'test'),
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [
            'Expect' => '100-Continue',
        ], 'test');
        $handler = new Handler\CurlMultiHandler();
        $response = $handler($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('Hello', $response->getHeaderLine('Test'));
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
        self::assertSame('test', (string) $response->getBody());
    }

    public function testCreatesConnectException()
    {
        $m = new \ReflectionMethod(CurlFactory::class, 'finishError');

        if (PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }

        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);
        $easy->errno = \CURLE_COULDNT_CONNECT;
        $response = $m->invoke(
            null,
            static function () {
            },
            $easy,
            $factory
        );

        $this->expectException(ConnectException::class);
        $response->wait();
    }

    public function testAddsTimeouts()
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'timeout' => 0.1,
            'connect_timeout' => 0.2,
        ]);
        self::assertEquals(100, $_SERVER['_curl'][\CURLOPT_TIMEOUT_MS]);
        self::assertEquals(200, $_SERVER['_curl'][\CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testAddsStreamingBody()
    {
        $f = new CurlFactory(3);
        $bd = Psr7\FnStream::decorate(Psr7\Utils::streamFor('foo'), [
            'getSize' => static function () {
                return null;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $bd);
        $f->create($request, []);
        self::assertEquals(1, $_SERVER['_curl'][\CURLOPT_UPLOAD]);
        self::assertIsCallable($_SERVER['_curl'][\CURLOPT_READFUNCTION]);
    }

    public function testEnsuresDirExistsBeforeThrowingWarning()
    {
        $f = new CurlFactory(3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory /does/not/exist/so does not exist for sink value of /does/not/exist/so/error.txt');
        $f->create(new Psr7\Request('GET', Server::$url), [
            'sink' => '/does/not/exist/so/error.txt',
        ]);
    }

    public function testClosesIdleHandles()
    {
        $f = new CurlFactory(3);
        $req = new Psr7\Request('GET', Server::$url);
        $easy = $f->create($req, []);
        $h1 = $easy->handle;
        $f->release($easy);
        self::assertCount(1, self::readIdleHandles($f));
        $easy = $f->create($req, []);
        self::assertSame($easy->handle, $h1);
        $easy2 = $f->create($req, []);
        $easy3 = $f->create($req, []);
        $easy4 = $f->create($req, []);
        $f->release($easy);
        self::assertCount(1, self::readIdleHandles($f));
        $f->release($easy2);
        self::assertCount(2, self::readIdleHandles($f));
        $f->release($easy3);
        self::assertCount(3, self::readIdleHandles($f));
        $f->release($easy4);
        self::assertCount(3, self::readIdleHandles($f));
    }

    public function testRejectsPromiseWhenCreateResponseFails()
    {
        Server::flush();
        Server::enqueueRaw(999, 'Incorrect', ['X-Foo' => 'bar'], 'abc 123');

        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
        $called = false;
        $promise = $handler($req, [
            'on_headers' => static function () use (&$called): void {
                $called = true;
            },
        ]);

        try {
            $promise->wait();
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
        }
    }

    public function testCreateResponseFailureDoesNotExposeStaleCurlResponse()
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);
        $easy->response = new Psr7\Response(100);
        $easy->errno = \CURLE_WRITE_ERROR;
        $easy->createResponseException = new \InvalidArgumentException(
            'Status code must be an integer value between 1xx and 5xx.'
        );

        $promise = CurlFactory::finish(
            static function () {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered while creating the response',
                $e->getMessage()
            );
            self::assertFalse($e->hasResponse());
            self::assertNull($e->getResponse());
            self::assertSame($easy->createResponseException, $e->getPrevious());
        }
    }

    public function testEnsuresOnHeadersIsCallable()
    {
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();

        $this->expectException(\InvalidArgumentException::class);
        $handler($req, ['on_headers' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
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
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
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
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $got = null;

        $stream = Psr7\Utils::streamFor();
        $stream = Psr7\FnStream::decorate($stream, [
            'write' => static function ($data) use ($stream, &$got) {
                self::assertNotNull($got);

                return $stream->write($data);
            },
        ]);

        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'sink' => $stream,
            'on_headers' => static function (ResponseInterface $res) use (&$got) {
                $got = $res;
                self::assertEquals('bar', $res->getHeaderLine('X-Foo'));
            },
        ]);

        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('abc 123', (string) $response->getBody());
    }

    public static function trailerStatusLineProvider(): iterable
    {
        yield 'http/2' => ["HTTP/2 200 \r\n"];
        yield 'http/1.1 chunked' => ["HTTP/1.1 200 OK\r\n"];
    }

    /**
     * @dataProvider trailerStatusLineProvider
     */
    public function testPreservesHeadersWhenTrailersArrive(string $statusLine)
    {
        $factory = new CurlFactory(1);
        $statuses = [];
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_headers' => static function (ResponseInterface $response) use (&$statuses) {
                $statuses[] = $response->getStatusCode();
            },
        ]);

        try {
            self::receiveCurlHeaders($easy, [
                $statusLine,
                "Content-Type: text/plain\r\n",
                "\r\n",
            ]);

            self::assertNotNull($easy->response);

            self::receiveCurlHeaders($easy, [
                "Foo: bar\r\n",
                "X-Dup: 1\r\n",
                "X-Dup: 2\r\n",
                "X-Empty:\r\n",
            ]);

            self::assertSame(
                [\trim($statusLine), 'Content-Type: text/plain'],
                $easy->headers
            );
            self::assertSame(200, $easy->response->getStatusCode());
            self::assertSame([200], $statuses);
        } finally {
            $factory->release($easy);
        }
    }

    public function testIgnoresBlankLineAfterTrailers()
    {
        $factory = new CurlFactory(1);
        $onHeadersCalls = 0;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_headers' => static function () use (&$onHeadersCalls) {
                ++$onHeadersCalls;
            },
        ]);

        try {
            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 200 OK\r\n",
                "Content-Type: text/plain\r\n",
                "\r\n",
                "Foo: bar\r\n",
                "\r\n",
            ]);

            self::assertNull($easy->createResponseException);
            self::assertSame(1, $onHeadersCalls);
            self::assertSame(
                ['HTTP/1.1 200 OK', 'Content-Type: text/plain'],
                $easy->headers
            );
        } finally {
            $factory->release($easy);
        }
    }

    public static function interimResponseProvider(): iterable
    {
        yield '100 continue' => [
            ["HTTP/1.1 100 Continue\r\n", "\r\n"],
            [100, 200],
        ];
        yield '103 early hints' => [
            ["HTTP/1.1 103 Early Hints\r\n", "Link: </style.css>; rel=preload\r\n", "\r\n"],
            [103, 200],
        ];
        yield 'connect established' => [
            ["HTTP/1.1 200 Connection established\r\n", "\r\n"],
            [200, 200],
        ];
    }

    /**
     * @dataProvider interimResponseProvider
     *
     * @param list<string> $interimLines
     * @param list<int>    $expectedStatuses
     */
    public function testReplacesInterimResponseBlocksWithFinalResponse(array $interimLines, array $expectedStatuses)
    {
        $factory = new CurlFactory(1);
        $statuses = [];
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_headers' => static function (ResponseInterface $response) use (&$statuses) {
                $statuses[] = $response->getStatusCode();
            },
        ]);

        try {
            self::receiveCurlHeaders($easy, $interimLines);
            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 200 OK\r\n",
                "Content-Length: 0\r\n",
                "\r\n",
            ]);

            self::assertSame(
                ['HTTP/1.1 200 OK', 'Content-Length: 0'],
                $easy->headers
            );
            self::assertNotNull($easy->response);
            self::assertSame(200, $easy->response->getStatusCode());
            self::assertSame($expectedStatuses, $statuses);

            self::receiveCurlHeaders($easy, [
                "Foo: bar\r\n",
            ]);

            self::assertSame(
                ['HTTP/1.1 200 OK', 'Content-Length: 0'],
                $easy->headers
            );
        } finally {
            $factory->release($easy);
        }
    }

    public function testStartsFreshHeaderBlockAfterIntermediateTrailerFields()
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        try {
            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 401 Unauthorized\r\n",
                "WWW-Authenticate: Negotiate\r\n",
                "\r\n",
                "X-Challenge-Trailer: 1\r\n",
            ]);

            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 200 OK\r\n",
                "\r\n",
                "Foo: bar\r\n",
            ]);

            self::assertSame(['HTTP/1.1 200 OK'], $easy->headers);
            self::assertSame(200, $easy->response->getStatusCode());
        } finally {
            $factory->release($easy);
        }
    }

    public function testDoesNotStartFreshHeaderBlockForMalformedHttpTrailerLine()
    {
        $factory = new CurlFactory(1);
        $onHeadersCalls = 0;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_headers' => static function () use (&$onHeadersCalls) {
                ++$onHeadersCalls;
            },
        ]);

        try {
            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 200 OK\r\n",
                "Content-Type: text/plain\r\n",
                "\r\n",
                " HTTP/1.1 204 No Content\r\n",
                "HTTP/1.1\t204 No Content\r\n",
                "HTTP/1.1  204 No Content\r\n",
                "HTTP/foo: not a status line\r\n",
                "HTTP/1.1 200abc Weird: not a status line\r\n",
                "Foo: bar\r\n",
                "\r\n",
            ]);

            self::assertNull($easy->createResponseException);
            self::assertSame(1, $onHeadersCalls);
            self::assertSame(
                ['HTTP/1.1 200 OK', 'Content-Type: text/plain'],
                $easy->headers
            );
            self::assertNotNull($easy->response);
            self::assertSame(200, $easy->response->getStatusCode());
            self::assertSame(['Foo: bar'], $easy->trailers);
        } finally {
            $factory->release($easy);
        }
    }

    /**
     * @dataProvider trailerStatusLineProvider
     */
    public function testCollectsTrailersAfterResponseBody(string $statusLine)
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        try {
            self::receiveCurlHeaders($easy, [
                $statusLine,
                "Content-Type: text/plain\r\n",
                "\r\n",
            ]);

            self::assertSame([], $easy->trailers);

            self::receiveCurlHeaders($easy, [
                "Foo: bar\r\n",
                "X-Dup: 1\r\n",
                "X-Dup: 2\r\n",
                "X-Empty:\r\n",
                "\r\n",
            ]);

            self::assertSame(
                ['Foo: bar', 'X-Dup: 1', 'X-Dup: 2', 'X-Empty:'],
                $easy->trailers
            );
        } finally {
            $factory->release($easy);
        }
    }

    public function testDiscardsMalformedTrailerLines()
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        try {
            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 200 OK\r\n",
                "Transfer-Encoding: chunked\r\n",
                "\r\n",
                "X-Checksum: abc\r\n",
                ": pseudo\r\n",
                " Bad-Leading-Space: value\r\n",
                "Bad Name: value\r\n",
                "Bad\tName: value\r\n",
                "Bad/Name: value\r\n",
                "Bad\x01Name: value\r\n",
                "Bad: value\x00\r\n",
                "Bad: value\rmore\r\n",
                "Bad: value\nmore\r\n",
                "Bad: value\x7F\r\n",
                "HTTP/1.1\t200 OK\r\n",
                "HTTP/1.1  200 OK\r\n",
                " folded-continuation\r\n",
                "junk-no-colon\r\n",
                "X-Valid-After: ok\r\n",
                "\r\n",
            ]);

            self::assertNull($easy->createResponseException);
            self::assertSame(['X-Checksum: abc', 'X-Valid-After: ok'], $easy->trailers);
        } finally {
            $factory->release($easy);
        }
    }

    public function testDiscardsIntermediateTrailersWhenNewHeaderBlockStarts()
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        try {
            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 401 Unauthorized\r\n",
                "WWW-Authenticate: Negotiate\r\n",
                "\r\n",
                "X-Challenge-Trailer: 1\r\n",
            ]);

            self::assertSame(['X-Challenge-Trailer: 1'], $easy->trailers);

            self::receiveCurlHeaders($easy, [
                "HTTP/1.1 200 OK\r\n",
                "\r\n",
                "Foo: bar\r\n",
            ]);

            self::assertSame(['Foo: bar'], $easy->trailers);
        } finally {
            $factory->release($easy);
        }
    }

    public function testInvokesOnTrailersWithParsedTrailers()
    {
        $factory = new CurlFactory(1);
        $gotTrailers = null;
        $gotResponse = null;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_trailers' => static function (array $trailers, ResponseInterface $response) use (&$gotTrailers, &$gotResponse) {
                $gotTrailers = $trailers;
                $gotResponse = $response;
            },
        ]);

        self::receiveCurlHeaders($easy, [
            "HTTP/2 200 \r\n",
            "content-type: text/plain\r\n",
            "\r\n",
            "x-status: 0\r\n",
            "x-dup: 1\r\n",
            "x-dup: 2\r\n",
        ]);

        $response = CurlFactory::finish(
            static function () {
            },
            $easy,
            $factory
        )->wait();

        self::assertSame(['x-status' => ['0'], 'x-dup' => ['1', '2']], $gotTrailers);
        self::assertSame($response, $gotResponse);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testOnTrailersDoesNotExposeMalformedTrailerFields()
    {
        $factory = new CurlFactory(1);
        $gotTrailers = null;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_trailers' => static function (array $trailers, ResponseInterface $response) use (&$gotTrailers): void {
                $gotTrailers = $trailers;
            },
        ]);

        self::receiveCurlHeaders($easy, [
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: text/plain\r\n",
            "\r\n",
            "x-valid: ok\r\n",
            "Bad: value\x00\r\n",
            " Bad-Leading-Space: value\r\n",
            "\r\n",
        ]);

        CurlFactory::finish(
            static function () {
            },
            $easy,
            $factory
        )->wait();

        self::assertSame(['x-valid' => ['ok']], $gotTrailers);
    }

    public function testOnTrailersReceivesRewoundResponseBody()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $bodyPosition = null;
        $bodyContents = null;
        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'on_trailers' => static function (array $trailers, ResponseInterface $response) use (&$bodyPosition, &$bodyContents) {
                $bodyPosition = $response->getBody()->tell();
                $bodyContents = (string) $response->getBody();
            },
        ]);

        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $bodyPosition);
        self::assertSame('abc 123', $bodyContents);
    }

    public function testInvokesOnTrailersWithEmptyArrayAfterOnStats()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $order = [];
        $gotTrailers = null;
        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'on_stats' => static function (TransferStats $stats) use (&$order) {
                $order[] = 'on_stats';
            },
            'on_trailers' => static function (array $trailers, ResponseInterface $response) use (&$order, &$gotTrailers) {
                $order[] = 'on_trailers';
                $gotTrailers = $trailers;
            },
        ]);

        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $gotTrailers);
        self::assertSame(['on_stats', 'on_trailers'], $order);
    }

    public function testRejectsPromiseWhenOnTrailersThrows()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'on_trailers' => static function (): void {
                throw new \Error('test');
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered during the on_trailers event',
                $e->getMessage()
            );
            self::assertInstanceOf(\Error::class, $e->getPrevious());
            self::assertTrue($e->hasResponse());
            self::assertSame(200, $e->getResponse()->getStatusCode());
        }
    }

    public function testDoesNotInvokeOnTrailersOnTransferError()
    {
        $req = new Psr7\Request('GET', 'http://127.0.0.1:123');
        $called = false;
        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
            'on_trailers' => static function () use (&$called) {
                $called = true;
            },
        ]);

        $promise->wait(false);
        self::assertFalse($called);
    }

    public function testDoesNotInvokeOnTrailersWhenOnHeadersFails()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $called = false;
        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'on_headers' => static function (): void {
                throw new \Exception('test');
            },
            'on_trailers' => static function () use (&$called) {
                $called = true;
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
            self::assertFalse($called);
        }
    }

    public function testInvokesOnStatsOnSuccess()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200)]);
        $req = new Psr7\Request('GET', Server::$url);
        $gotStats = null;
        $handler = new Handler\CurlHandler();
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
        self::assertIsInt($gotStats->getHandlerErrorData());
        self::assertArrayHasKey('appconnect_time', $gotStats->getHandlerStats());
    }

    public function testRewindsBodyIfPossible()
    {
        $body = Psr7\Utils::streamFor(\str_repeat('x', 1024 * 1024 * 2));
        $body->seek(1024 * 1024);
        self::assertSame(1024 * 1024, $body->tell());

        $req = new Psr7\Request('POST', 'https://www.example.com', [
            'Content-Length' => (string) (1024 * 1024 * 2),
        ], $body);
        $factory = new CurlFactory(1);
        $factory->create($req, []);

        self::assertSame(0, $body->tell());
    }

    public function testDoesNotRewindUnseekableBody()
    {
        $body = Psr7\Utils::streamFor(\str_repeat('x', 1024 * 1024 * 2));
        $body->seek(1024 * 1024);
        $body = new Psr7\NoSeekStream($body);
        self::assertSame(1024 * 1024, $body->tell());

        $req = new Psr7\Request('POST', 'https://www.example.com', [
            'Content-Length' => (string) (1024 * 1024),
        ], $body);
        $factory = new CurlFactory(1);
        $factory->create($req, []);

        self::assertSame(1024 * 1024, $body->tell());
    }

    public function testRelease()
    {
        $factory = new CurlFactory(1);
        $easyHandle = new EasyHandle();
        $easyHandle->handle = \curl_init();

        self::assertEmpty($factory->release($easyHandle));
    }

    /**
     * https://github.com/guzzle/guzzle/issues/2735
     */
    public function testBodyEofOnWindows()
    {
        $expectedLength = 4097;

        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [
                'Content-Length' => (string) $expectedLength,
            ], \str_repeat('x', $expectedLength)),
        ]);

        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $promise = $handler($request, []);
        $response = $promise->wait();
        $body = $response->getBody();

        $actualLength = 0;
        while (!$body->eof()) {
            $chunk = $body->read(4096);
            $actualLength += \strlen($chunk);
        }
        self::assertSame($expectedLength, $actualLength);
    }

    public function testHandlesGarbageHttpServerGracefully()
    {
        $a = new Handler\CurlMultiHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('cURL error 1: Received HTTP/0.9 when not allowed');

        $a(new Psr7\Request('GET', Server::$url.'guzzle-server/garbage'), [])->wait();
    }

    public function testHandlesInvalidStatusCodeGracefully()
    {
        $a = new Handler\CurlMultiHandler();

        try {
            $a(new Psr7\Request('GET', Server::$url.'guzzle-server/bad-status'), [])->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered while creating the response',
                $e->getMessage()
            );
            self::assertFalse($e->hasResponse());
            self::assertNull($e->getResponse());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
        }
    }

    private static function readIdleHandles(CurlFactory $factory): array
    {
        $readHandles = \Closure::bind(static function (CurlFactory $factory): array {
            return $factory->handles;
        }, null, CurlFactory::class);

        return $readHandles($factory);
    }

    private static function assertNoProxyOption(string $expected): void
    {
        if (!\defined('CURLOPT_NOPROXY')) {
            return;
        }

        self::assertSame($expected, $_SERVER['_curl'][(int) \constant('CURLOPT_NOPROXY')]);
    }

    private static function skipIfWindows(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Environment variables are case-insensitive on Windows.');
        }
    }

    /**
     * Runs the callback with only the given proxy environment variables set,
     * restoring the process environment afterwards.
     *
     * @param array<string, string> $env
     */
    private static function withProxyEnvironment(array $env, callable $test): void
    {
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = \getenv($name, true);
            \putenv($name);
        }
        foreach ($env as $name => $value) {
            \putenv($name.'='.$value);
        }

        try {
            $test();
        } finally {
            foreach ($names as $name) {
                \putenv($name);
            }
            foreach ($previous as $name => $value) {
                if ($value !== false) {
                    \putenv($name.'='.$value);
                }
            }
        }
    }

    /**
     * @param array<int|string, mixed> $options
     */
    private static function createOnFactory(CurlFactory $factory, string $version, string $uri, array $options): EasyHandle
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $version,
            'features' => 0,
        ]);

        try {
            return $factory->create(new Psr7\Request('GET', $uri), $options);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    private static function proxyHeaderOption(): int
    {
        if (!\defined('CURLOPT_PROXYHEADER')) {
            self::markTestSkipped('CURLOPT_PROXYHEADER is not available.');
        }

        return (int) \constant('CURLOPT_PROXYHEADER');
    }

    private static function skipIfCurlNoProxyIsUnavailable(): void
    {
        if (!\defined('CURLOPT_NOPROXY')) {
            self::markTestSkipped('CURLOPT_NOPROXY is not available.');
        }
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function getEffectiveProxy(array $conf): ?string
    {
        $method = new \ReflectionMethod(CurlFactory::class, 'getEffectiveProxy');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke(null, $conf);
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function normalizeCurlHeaderOptions(array &$conf): void
    {
        $method = new \ReflectionMethod(CurlFactory::class, 'normalizeCurlHeaderOptions');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs(null, [&$conf]);
    }

    private static function skipIfProxyHeaderSeparationUnavailable(): void
    {
        if (
            !\defined('CURLOPT_PROXYHEADER')
            || !\defined('CURLOPT_HEADEROPT')
            || !\defined('CURLHEADER_SEPARATE')
        ) {
            self::markTestSkipped('Proxy header separation cURL constants are unavailable.');
        }
    }

    /**
     * @param array<int|string, mixed> $conf
     *
     * @return array<int|string, mixed>
     */
    private static function invokeProxyAuthorizationHeaderHandling(string $version, RequestInterface $request, array $conf): array
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $version,
            'features' => 0,
        ]);

        try {
            $method = new \ReflectionMethod(CurlFactory::class, 'applyProxyAuthorizationHeaderHandling');
            if (\PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }

            $method->invokeArgs(null, [$request, &$conf]);

            return $conf;
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    private static function redactProxyUserInfo(string $error, ?string $proxy): string
    {
        $method = new \ReflectionMethod(CurlFactory::class, 'redactProxyUserInfo');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke(null, $error, $proxy);
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function computeProxyTunnelSignature(string $version, string $uri, array $conf): ?string
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $version,
            'features' => 0,
        ]);

        try {
            $method = new \ReflectionMethod(CurlFactory::class, 'proxyTunnelSignature');
            if (\PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }

            return $method->invoke(null, new Psr7\Request('GET', $uri), $conf);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    private static function computeRequiresFreshForAuthenticatedProxy(string $version, string $uri, array $conf): bool
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $version,
            'features' => 0,
        ]);

        try {
            $method = new \ReflectionMethod(CurlFactory::class, 'requiresFreshConnectionForAuthenticatedProxy');
            if (\PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }

            return $method->invoke(null, new Psr7\Request('GET', $uri), $conf[\CURLOPT_PROXY], $conf);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (!\function_exists('curl_share_init') || !\defined('CURLOPT_SHARE')) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }

    private static function curlSslFeature(): int
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is unavailable.');
        }

        return \CURL_VERSION_SSL;
    }

    /**
     * @param list<string> $headers
     */
    private static function receiveCurlHeaders(EasyHandle $easy, array $headers): callable
    {
        $header = $_SERVER['_curl'][\CURLOPT_HEADERFUNCTION];

        foreach ($headers as $line) {
            self::assertSame(\strlen($line), $header($easy->handle, $line));
        }

        return $header;
    }

    /**
     * @param array{version: string, features: int}|false|null $versionInfo
     *
     * @return array{version: string, features: int}|false|null
     */
    private static function setCurlVersionInfo($versionInfo)
    {
        $property = new \ReflectionProperty(CurlVersion::class, 'versionInfo');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $previousVersionInfo = $property->getValue();
        $property->setValue(null, $versionInfo);

        return $previousVersionInfo;
    }
}
