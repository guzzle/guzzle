<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Handler;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;
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

    public function testCreatesCurlHandle(): void
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
        self::assertCurlProtocols(['http', 'https']);
        self::assertContains('Expect:', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Accept:', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Content-Type:', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Hi: 123', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertContains('Host: 127.0.0.1:8126', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
    }

    public function testCloseClearsIdleHandles(): void
    {
        $factory = new CurlFactory(3);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        $factory->release($easy);
        self::assertCount(1, self::readIdleHandles($factory));

        $factory->close();

        self::assertSame([], self::readIdleHandles($factory));
    }

    public function testReleaseClearsCallbacksBeforeDiscardingHandle(): void
    {
        $factory = new CurlFactory(0);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
        ]);

        $factory->release($easy);

        self::assertArrayNotHasKey(\CURLOPT_HEADERFUNCTION, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_READFUNCTION, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_WRITEFUNCTION, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::assertArrayNotHasKey((int) \constant('CURLOPT_XFERINFOFUNCTION'), $_SERVER['_curl']);
        }
        self::assertSame([], self::readIdleHandles($factory));
    }

    public function testCloseIsIdempotent(): void
    {
        $factory = new CurlFactory(3);

        $factory->close();
        $factory->close();

        self::assertSame([], self::readIdleHandles($factory));
    }

    public function testCreateAfterCloseThrows(): void
    {
        $factory = new CurlFactory(3);
        $factory->close();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot use the cURL factory after it has been closed.');

        $factory->create(new Psr7\Request('GET', Server::$url), []);
    }

    public function testReleaseAfterCloseThrows(): void
    {
        $factory = new CurlFactory(3);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);

        try {
            $factory->close();

            $this->expectException(\BadMethodCallException::class);
            $this->expectExceptionMessage('Cannot use the cURL factory after it has been closed.');

            $factory->release($easy);
        } finally {
            if (isset($easy->handle) && \PHP_VERSION_ID < 80000) {
                \curl_close($easy->handle);
            }
        }
    }

    public function testSendsHeadRequests(): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), []);
        $response->wait();
        self::assertTrue($_SERVER['_curl'][\CURLOPT_NOBODY]);
        $checks = [\CURLOPT_READFUNCTION, \CURLOPT_FILE, \CURLOPT_INFILE];
        foreach ($checks as $check) {
            self::assertArrayNotHasKey($check, $_SERVER['_curl']);
        }
        self::assertEquals('HEAD', Server::received()[0]->getMethod());
    }

    public function testCanAddCustomCurlOptions(): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);
        $a($req, ['curl' => [\CURLOPT_LOW_SPEED_LIMIT => 10]]);
        self::assertEquals(10, $_SERVER['_curl'][\CURLOPT_LOW_SPEED_LIMIT]);
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

    /**
     * @dataProvider enabledShareModeProvider
     */
    public function testRejectsRequestLevelShareWhenConfiguredCurlShareHandleExists(string $shareMode): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        $requestShareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        self::assertNotFalse($requestShareHandle);
        $factory = new CurlFactory(3, $shareMode, $shareHandle);

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

    public static function enabledShareModeProvider(): iterable
    {
        yield 'handler prefer' => [TransportSharing::HANDLER_PREFER];
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent prefer' => [TransportSharing::PERSISTENT_PREFER];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
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

    public function testRejectsUnsupportedCurlOption(): void
    {
        $option = 999999;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage((string) $option);
        $this->expectExceptionMessage('outside the built-in cURL handlers\' allow-list');

        (new CurlFactory(3))->create(new Psr7\Request('GET', Server::$url), [
            'curl' => [
                $option => true,
            ],
        ]);
    }

    public function testPersistentRequireRejectsFreshConnect(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        $factory = new CurlFactory(3, TransportSharing::PERSISTENT_REQUIRE, $shareHandle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CURLOPT_FRESH_CONNECT');

            $factory->create(new Psr7\Request('GET', 'https://example.com'), [
                'curl' => [
                    \CURLOPT_FRESH_CONNECT => true,
                ],
            ]);
        } finally {
            self::closeShareHandleOnPhp7($shareHandle);
        }
    }

    public function testPersistentRequireRejectsForbidReuse(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        $factory = new CurlFactory(3, TransportSharing::PERSISTENT_REQUIRE, $shareHandle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CURLOPT_FORBID_REUSE');

            $factory->create(new Psr7\Request('GET', 'https://example.com'), [
                'curl' => [
                    \CURLOPT_FORBID_REUSE => true,
                ],
            ]);
        } finally {
            self::closeShareHandleOnPhp7($shareHandle);
        }
    }

    public function testPersistentRequireAllowsExplicitReuseOptionsSetToFalse(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        $factory = new CurlFactory(3, TransportSharing::PERSISTENT_REQUIRE, $shareHandle);
        $easy = $factory->create(new Psr7\Request('GET', 'https://example.com'), [
            'curl' => [
                \CURLOPT_FRESH_CONNECT => false,
                \CURLOPT_FORBID_REUSE => false,
            ],
        ]);

        try {
            self::assertFalse($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
            self::assertFalse($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        } finally {
            $factory->release($easy);
            self::closeShareHandleOnPhp7($shareHandle);
        }
    }

    public function testPersistentRequireRejectsRequestsThatRequireFreshProxyTunnelConnections(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $proxyHeaderOption = self::proxyHeaderOption();
        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        $factory = new CurlFactory(3, TransportSharing::PERSISTENT_REQUIRE, $shareHandle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('fresh proxy tunnel connection');

            $factory->create(new Psr7\Request('GET', 'https://example.com'), [
                'proxy' => 'http://proxy.example.com:8080',
                'curl' => [
                    $proxyHeaderOption => ['Proxy-Authorization: Basic abc'],
                ],
            ]);
        } finally {
            self::closeShareHandleOnPhp7($shareHandle);
        }
    }

    public function testCloseReleasesConfiguredCurlShareHandle(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);
        $factory = new CurlFactory(3, TransportSharing::HANDLER_PREFER, $shareHandle);

        self::assertSame($shareHandle, self::readShareHandle($factory));

        $factory->close();

        self::assertNull(self::readShareHandle($factory));
    }

    public function testRejectsConflictingCurlOptions(): void
    {
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_HTTP_VERSION');
        $this->expectExceptionMessage('request protocol version');

        $a($req, ['curl' => [\CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_0]]);
    }

    /**
     * @dataProvider additionalConflictingCurlOptionProvider
     *
     * @param mixed $value
     */
    public function testRejectsAdditionalConflictingCurlOptions(string $constant, int $option, $value, string $replacement): void
    {
        try {
            (new CurlFactory(3))->create(new Psr7\Request('GET', Server::$url), [
                'curl' => [
                    $option => $value,
                ],
            ]);

            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($constant, $e->getMessage());
            self::assertStringContainsString($replacement, $e->getMessage());
        }
    }

    public static function additionalConflictingCurlOptionProvider(): array
    {
        $cases = [
            'cookie header' => ['CURLOPT_COOKIE', 'name=value', 'the "Cookie" request header or Guzzle cookie middleware'],
        ];

        $available = [];
        foreach ($cases as $name => $case) {
            [$constant, $value, $replacement] = $case;
            if (\defined($constant)) {
                $available[$name] = [$constant, (int) \constant($constant), $value, $replacement];
            }
        }

        return $available;
    }

    public function testRejectsRequestLevelCurlShareOption(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $shareHandle = \curl_share_init();
        self::assertNotFalse($shareHandle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CURLOPT_SHARE');
            $this->expectExceptionMessage('transport_sharing');

            (new CurlFactory(3))->create(new Psr7\Request('GET', Server::$url), [
                'curl' => [
                    \CURLOPT_SHARE => $shareHandle,
                ],
            ]);
        } finally {
            if (PHP_VERSION_ID < 80000) {
                \curl_share_close($shareHandle);
            }
        }
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
        yield 'persistent prefer' => [TransportSharing::PERSISTENT_PREFER];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
        yield 'invalid' => ['invalid'];
    }

    public function testRejectsStreamContextOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_context');

        (new CurlFactory(3))->create(new Psr7\Request('GET', Server::$url), [
            'stream_context' => [],
        ]);
    }

    public function testProtocolsOptionCanRestrictCurlProtocols(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'https://example.com'), ['protocols' => ['https']]);

        self::assertCurlProtocols(['https']);
    }

    public function testProtocolsOptionFallsBackToCurlProtocolsWhenRuntimeDoesNotSupportProtocolsStr(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.84.0',
            'features' => self::curlSslFeature(),
        ]);

        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), ['protocols' => ['https']]);

            self::assertSame(\CURLPROTO_HTTPS, $_SERVER['_curl'][\CURLOPT_PROTOCOLS]);
            if (\defined('CURLOPT_PROTOCOLS_STR')) {
                self::assertArrayNotHasKey((int) \constant('CURLOPT_PROTOCOLS_STR'), $_SERVER['_curl']);
            }
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testProtocolsOptionUsesProtocolsStrWhenRuntimeSupportsIt(): void
    {
        if (!\defined('CURLOPT_PROTOCOLS_STR')) {
            self::markTestSkipped('CURLOPT_PROTOCOLS_STR is not available.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.85.0',
            'features' => self::curlSslFeature(),
        ]);

        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', 'https://example.com'), ['protocols' => ['https']]);

            self::assertSame('https', $_SERVER['_curl'][(int) \constant('CURLOPT_PROTOCOLS_STR')]);
            self::assertArrayNotHasKey(\CURLOPT_PROTOCOLS, $_SERVER['_curl']);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testProtocolsOptionRejectsDisallowedCurlScheme(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('not allowed by the protocols request option');

        $f->create(new Psr7\Request('GET', 'http://example.com'), ['protocols' => ['https']]);
    }

    /**
     * @dataProvider invalidProtocolsProvider
     *
     * @param mixed $protocols
     */
    public function testProtocolsOptionRejectsInvalidValues($protocols): void
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

    public function testThrowsWhenCurlOptionCannotBeApplied(): void
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

    public function testThrowsWhenCurlOptionNameIsInvalid(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cURL option "not-a-curl-option".');

        $f->create(
            new Psr7\Request('GET', Server::$url),
            ['curl' => ['not-a-curl-option' => true]]
        );
    }

    public function testRejectsNonCallableOnStats(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('on_stats must be callable');

        $f->create(new Psr7\Request('GET', 'http://example.com'), ['on_stats' => false]);
    }

    public function testValidatesVerify(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL CA bundle not found: /does/not/exist');
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => '/does/not/exist']);
    }

    public function testCanSetVerifyToFile(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_CAINFO]);
        self::assertEquals(2, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
    }

    public function testCanSetVerifyToDir(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __DIR__]);
        self::assertEquals(__DIR__, $_SERVER['_curl'][\CURLOPT_CAPATH]);
        self::assertEquals(2, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsVerifyAsTrue(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => true]);
        self::assertEquals(2, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
        self::assertArrayNotHasKey(\CURLOPT_CAINFO, $_SERVER['_curl']);
    }

    public function testCanDisableVerify(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['verify' => false]);
        self::assertEquals(0, $_SERVER['_curl'][\CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($_SERVER['_curl'][\CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsProxy(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['proxy' => 'http://bar.com']);
        self::assertEquals('http://bar.com', $_SERVER['_curl'][\CURLOPT_PROXY]);
        self::assertSame('', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
    }

    public function testAddsViaScheme(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'proxy' => ['http' => 'http://bar.com', 'https' => 'https://t'],
        ]);
        self::assertEquals('http://bar.com', $_SERVER['_curl'][\CURLOPT_PROXY]);
        self::assertSame('', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
        $this->checkNoProxyForHost('http://test.test.com', ['test.test.com'], false);
        $this->checkNoProxyForHost('http://example.com', ['EXAMPLE.com'], false);
        $this->checkNoProxyForHost('http://foo.example.com', ['EXAMPLE.com'], false);
        $this->checkNoProxyForHost('http://test.test.com', ['.test.com'], false);
        $this->checkNoProxyForHost('http://test.test.com', 'test.test.com,example.com', false);
        $this->checkNoProxyForHost('http://example.com', ' EXAMPLE.com , other.com ', false);
        $this->checkNoProxyForHost('http://test.test.com', '.example.com,example.org', true);
        $this->checkNoProxyForHost('http://test.test.com', [], true);
        $this->checkNoProxyForHost('http://test.test.com', '', true);
        $this->checkNoProxyForHost('http://test.test.com', ['test.test.com:80'], false);
        $this->checkNoProxyForHost('https://test.test.com', ['test.test.com:443'], false);
        $this->checkNoProxyForHost('http://test.test.com:8080', ['test.test.com:8080'], false);
        $this->checkNoProxyForHost('http://test.test.com:8081', ['test.test.com:8080'], true);
        $this->checkNoProxyForHost('http://foo.test.com:8080', ['.test.com:8080'], false);
        $this->checkNoProxyForHost('http://test.com:8080', ['.test.com:8080'], true);
        $this->checkNoProxyForHost('http://[::1]:8080', ['[::1]:8080'], false);
        $this->checkNoProxyForHost('http://[::1]:8081', ['[::1]:8080'], true);
        $this->checkNoProxyForHost('http://[0:0:0:0:0:0:0:1]', ['::1'], false);
        $this->checkNoProxyForHost('http://[::1]:8081', ['[0:0:0:0:0:0:0:1]:8080'], true);
        $this->checkNoProxyForHost('http://test.test.com', ['*.test.com'], true);
        $this->checkNoProxyForHost('http://test.example.com', ['*.example.com'], true);
        $this->checkNoProxyForHost('http://test.test.com', ['*'], false);
        $this->checkNoProxyForHost('http://example.com', ['*:80'], false);
        $this->checkNoProxyForHost('https://example.com', ['*:80'], true);
        $this->checkNoProxyForHost('http://127.0.0.1', ['127.0.0.*'], true);
        $this->checkNoProxyForHost('http://192.168.1.10', ['192.168.0.0/16'], false);
        $this->checkNoProxyForHost('http://192.169.1.10', ['192.168.0.0/16'], true);
        $this->checkNoProxyForHost('http://[fd00::1]', ['fd00::/8'], false);
        $this->checkNoProxyForHost('http://[fe80::1]', ['fd00::/8'], true);
    }

    public function testForcesFreshConnectionForAuthenticatedHttpsProxyOnAffectedCurlVersion(): void
    {
        self::createWithCurlVersion('8.19.0', 'https://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
        ]);

        self::assertAuthenticatedProxyConnectionReuseOptions();
    }

    public function testDoesNotForceFreshConnectionForAuthenticatedHttpsProxyOnFixedCurlVersion(): void
    {
        self::createWithCurlVersion('8.20.0', 'https://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testDoesNotForceFreshConnectionForCurlProxyCredentialsOnFixedCurlVersion(): void
    {
        self::createWithCurlVersion('8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                \CURLOPT_PROXYUSERPWD => 'username:password',
            ],
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testForcesFreshConnectionForAuthenticatedHttpsProxyWithCurlProxyCredentialsOnAffectedCurlVersion(): void
    {
        self::createWithCurlVersion('8.19.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                \CURLOPT_PROXYUSERPWD => 'username:password',
            ],
        ]);

        self::assertAuthenticatedProxyConnectionReuseOptions();
    }

    public function testRejectsRawCurlProxyUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXY');

        (new CurlFactory(3))->create(new Psr7\Request('GET', 'https://example.com'), [
            'curl' => [
                \CURLOPT_PROXY => 'http://username:password@proxy.example.com:8080',
            ],
        ]);
    }

    public function testRejectsRawCurlProxyUrlWithCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXY');

        (new CurlFactory(3))->create(new Psr7\Request('GET', 'https://example.com'), [
            'curl' => [
                \CURLOPT_PROXY => 'http://proxy.example.com:8080',
                \CURLOPT_PROXYUSERPWD => 'username:password',
            ],
        ]);
    }

    public function testForcesFreshConnectionForAuthenticatedHttpProxyTunnelOnAffectedCurlVersion(): void
    {
        self::createWithCurlVersion('8.19.0', 'http://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
            'curl' => [
                \CURLOPT_HTTPPROXYTUNNEL => true,
            ],
        ]);

        self::assertAuthenticatedProxyConnectionReuseOptions();
    }

    public function testForcesFreshConnectionForProxyAuthorizationProxyHeaderOnFixedCurlVersion(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        self::createWithCurlVersion('8.20.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['Proxy-Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ='],
            ],
        ]);

        self::assertAuthenticatedProxyConnectionReuseOptions();
    }

    public function testDoesNotForceFreshConnectionForUnrelatedProxyHeader(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        self::createWithCurlVersion('8.18.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['X-Proxy-Header: value'],
            ],
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testDoesNotForceFreshConnectionForEmptyProxyAuthorizationProxyHeader(): void
    {
        $proxyHeaderOption = self::proxyHeaderOption();

        self::createWithCurlVersion('8.18.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
            'curl' => [
                $proxyHeaderOption => ['Proxy-Authorization:'],
            ],
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testRejectsRawCurlSocksProxyTypeWithProxyUrl(): void
    {
        if (!\defined('CURLPROXY_SOCKS5')) {
            self::markTestSkipped('CURLPROXY_SOCKS5 is not available.');
        }

        $proxyType = (int) \constant('CURLPROXY_SOCKS5');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXY');

        (new CurlFactory(3))->create(new Psr7\Request('GET', 'https://example.com'), [
            'curl' => [
                \CURLOPT_PROXY => 'proxy.example.com:1080',
                \CURLOPT_PROXYTYPE => $proxyType,
                \CURLOPT_PROXYUSERPWD => 'username:password',
            ],
        ]);
    }

    public function testRejectsRawCurlProxyOverride(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXY');

        $f->create(new Psr7\Request('GET', 'https://example.com'), [
            'proxy' => 'http://username:password@proxy-one.example.com:8080',
            'curl' => [
                \CURLOPT_PROXY => 'http://proxy-two.example.com:8080',
            ],
        ]);
    }

    public function testRejectsRawCurlProxyDisable(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_PROXY');

        $f->create(new Psr7\Request('GET', 'https://example.com'), [
            'proxy' => 'http://username:password@proxy.example.com:8080',
            'curl' => [
                \CURLOPT_PROXY => '',
            ],
        ]);
    }

    public function testDoesNotForceFreshConnectionForAuthenticatedHttpProxyRequest(): void
    {
        self::createWithCurlVersion('8.18.0', 'http://example.com', [
            'proxy' => 'http://username:password@proxy.example.com:8080',
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testDoesNotForceFreshConnectionForUnauthenticatedHttpsProxy(): void
    {
        self::createWithCurlVersion('8.18.0', 'https://example.com', [
            'proxy' => 'http://proxy.example.com:8080',
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testDoesNotForceFreshConnectionForAuthenticatedSocksProxy(): void
    {
        self::createWithCurlVersion('8.18.0', 'https://example.com', [
            'proxy' => 'socks5://username:password@proxy.example.com:1080',
        ]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testDoesNotForceFreshConnectionWhenNoProxyMatches(): void
    {
        self::createWithCurlVersion('8.18.0', 'https://example.com', [
            'proxy' => [
                'https' => 'http://username:password@proxy.example.com:8080',
                'no' => ['example.com'],
            ],
        ]);

        self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
        self::assertSame('*', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        self::assertArrayNotHasKey(\CURLOPT_FORBID_REUSE, $_SERVER['_curl']);
    }

    public function testAuthenticatedHttpsProxyReuseOptionsCanBeOverridden(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'https://example.com'), [
            'proxy' => 'http://username:password@proxy.example.com:8080',
            'curl' => [
                \CURLOPT_FRESH_CONNECT => false,
                \CURLOPT_FORBID_REUSE => false,
            ],
        ]);

        self::assertFalse($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertFalse($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    /**
     * @dataProvider invalidProxyOptionProvider
     *
     * @param mixed $proxy
     */
    public function testValidatesProxyOption($proxy): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $f->create(new Psr7\Request('GET', Server::$url), ['proxy' => $proxy]);
    }

    public static function invalidProxyOptionProvider(): array
    {
        return [
            [new \stdClass()],
            [['http' => new \stdClass()]],
            [['http' => 'http://bar.com', 'no' => new \stdClass()]],
            [['http' => 'http://bar.com', 'no' => [new \stdClass()]]],
        ];
    }

    /**
     * @param array<int, string>|string $noProxy
     */
    private function checkNoProxyForHost(string $url, $noProxy, bool $assertUseProxy): void
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
            self::assertSame(
                \parse_url($url, \PHP_URL_SCHEME) === 'https' ? 'https://t' : 'http://bar.com',
                $_SERVER['_curl'][\CURLOPT_PROXY]
            );
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
        } else {
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertSame('*', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
        }
    }

    public function testUsesProxy(): void
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

    public function testDefaultsHttpsToTls12Minimum(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'https://example.com'), []);

        self::assertEquals(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testDoesNotSetDefaultTlsMinimumForHttp(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', 'http://example.com'), []);

        self::assertArrayNotHasKey(\CURLOPT_SSLVERSION, $_SERVER['_curl']);
    }

    public function testRejectsRawCurlSslVersionOption(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_SSLVERSION');

        $f->create(new Psr7\Request('GET', 'https://example.com'), [
            'curl' => [\CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_1],
        ]);
    }

    public function testValidatesCryptoMethodInvalidMethod(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method request option: unknown version provided');
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => 123]);
    }

    public function testAddsCryptoMethodTls10(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]);
        self::assertEquals(\CURL_SSLVERSION_TLSv1_0, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testAddsCryptoMethodTls11(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT]);
        self::assertEquals(\CURL_SSLVERSION_TLSv1_1, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testAddsCryptoMethodTls12(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
        self::assertEquals(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testAddsCryptoMethodTls13(): void
    {
        if (!CurlVersion::supportsTls13()) {
            self::markTestSkipped('TLS 1.3 is not supported by this cURL installation.');
        }

        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]);
        self::assertEquals(\CURL_SSLVERSION_TLSv1_3, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testValidatesSslKey(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL private key not found: /does/not/exist');
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => '/does/not/exist']);
    }

    public function testAddsSslKey(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLKEY]);
    }

    public function testAddsSslKeyWithPassword(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__, 'test']]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLKEY]);
        self::assertEquals('test', $_SERVER['_curl'][\CURLOPT_SSLKEYPASSWD]);
    }

    public function testAddsSslKeyWhenUsingArraySyntaxButNoPassword(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__]]);

        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLKEY]);
    }

    public function testAddsSslKeyType(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'ssl_key' => __FILE__,
            'ssl_key_type' => 'pem',
        ]);

        self::assertSame('PEM', $_SERVER['_curl'][\CURLOPT_SSLKEYTYPE]);
    }

    public function testAllowsEngineSslKeyIdentifiers(): void
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
    public function testValidatesSslKeyType($sslKeyType): void
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
    public function testValidatesSslKeyOptionShape($sslKey): void
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

    public function testValidatesCert(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL certificate not found: /does/not/exist');
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => '/does/not/exist']);
    }

    public function testAddsCert(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => __FILE__]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLCERT]);
    }

    public function testAddsCertWithPassword(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), ['cert' => [__FILE__, 'test']]);
        self::assertEquals(__FILE__, $_SERVER['_curl'][\CURLOPT_SSLCERT]);
        self::assertEquals('test', $_SERVER['_curl'][\CURLOPT_SSLCERTPASSWD]);
    }

    public function testAddsCertWithArrayPathOnly(): void
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

    public function testAddsCertType(): void
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
    public function testValidatesCertType($certType): void
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
    public function testValidatesCertOptionShape($cert): void
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

    public function testAddsDerCert(): void
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

    public function testExplicitCertTypeOverridesCertExtension(): void
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

    public function testAddsP12Cert(): void
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

    public function testValidatesProgress(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('progress client option must be callable');
        $f->create(new Psr7\Request('GET', Server::$url), ['progress' => 'foo']);
    }

    public function testUsesXferInfoFunctionForProgressWhenAvailable(): void
    {
        $f = new CurlFactory(3);
        $easy = $f->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
        ]);

        try {
            if (\defined('CURLOPT_XFERINFOFUNCTION')) {
                self::assertArrayHasKey((int) \constant('CURLOPT_XFERINFOFUNCTION'), $_SERVER['_curl']);
                self::assertArrayNotHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
            } else {
                self::assertArrayHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
            }
        } finally {
            $f->release($easy);
        }
    }

    public function testProgressReturnValueControlsCurlAbort(): void
    {
        $f = new CurlFactory(3);
        $called = [];
        $easy = $f->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use (&$called): bool {
                $called = [$downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes];

                return $downloadedBytes > 0;
            },
        ]);

        try {
            $callback = $_SERVER['_curl'][self::progressCallbackOption()];

            self::assertSame(0, $callback($easy->handle, 10.0, 0.0, 2.0, 0.0));
            self::assertFalse($easy->progressAborted);
            self::assertNull($easy->progressException);
            self::assertSame([10, 0, 2, 0], $called);

            self::assertSame(1, $callback($easy->handle, 10.0, 1.0, 2.0, 0.0));
            self::assertTrue($easy->progressAborted);
            self::assertNull($easy->progressException);
            self::assertSame([10, 1, 2, 0], $called);
        } finally {
            $f->release($easy);
        }
    }

    public function testProgressOverflowValueAbortsCurlTransfer(): void
    {
        $f = new CurlFactory(3);
        $easy = $f->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (): void {
                self::fail('Progress callback should not receive overflowing values');
            },
        ]);

        try {
            $callback = $_SERVER['_curl'][self::progressCallbackOption()];

            self::assertSame(1, $callback($easy->handle, \INF, 0.0, 0.0, 0.0));
            self::assertFalse($easy->progressAborted);
            self::assertInstanceOf(\OverflowException::class, $easy->progressException);
        } finally {
            $f->release($easy);
        }
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testProgressTruthyReturnRejectsThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200, [], 'abc')]);
        $handler = $handlerFactory();

        try {
            $handler(new Psr7\Request('GET', Server::$url), [
                'progress' => static function (): bool {
                    return true;
                },
            ])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('The transfer was aborted by the progress callback', $e->getMessage());
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testProgressThrowableRejectsThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200, [], 'abc')]);
        $handler = $handlerFactory();
        $previous = new \Error('progress failed');

        try {
            $handler(new Psr7\Request('GET', Server::$url), [
                'progress' => static function () use ($previous): void {
                    throw $previous;
                },
            ])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('An error was encountered during the progress event', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }
    }

    public function testProgressAbortRejectsWithRequestException(): void
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (): bool {
                return true;
            },
        ]);

        $callback = $_SERVER['_curl'][self::progressCallbackOption()];
        self::assertSame(1, $callback($easy->handle, 0, 0, 0, 0));
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            )->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('The transfer was aborted by the progress callback', $e->getMessage());
        }
    }

    public function testProgressThrowableRejectsWithRequestException(): void
    {
        $factory = new CurlFactory(1);
        $previous = new \Error('boom');
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        $callback = $_SERVER['_curl'][self::progressCallbackOption()];
        self::assertSame(1, $callback($easy->handle, 0, 0, 0, 0));
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            )->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('An error was encountered during the progress event', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testProgressExceptionWinsOverAbortMarker(): void
    {
        $factory = new CurlFactory(1);
        $previous = new \RuntimeException('boom');
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);
        $easy->progressAborted = true;
        $easy->progressException = $previous;
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            )->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('An error was encountered during the progress event', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testAbortedByCallbackWithoutProgressMarkerUsesGenericCurlError(): void
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            )->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringStartsWith('cURL error '.\CURLE_ABORTED_BY_CALLBACK.':', $e->getMessage());
        }
    }

    public function testReleaseClearsXferInfoCallbackBeforeDiscardingHandle(): void
    {
        if (!\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::markTestSkipped('CURLOPT_XFERINFOFUNCTION is not available.');
        }

        $option = (int) \constant('CURLOPT_XFERINFOFUNCTION');
        $factory = new CurlFactory(0);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
        ]);

        $factory->release($easy);

        self::assertArrayNotHasKey($option, $_SERVER['_curl']);
        self::assertSame([], self::readIdleHandles($factory));
    }

    public function testReleaseClearsXferInfoCallbackBeforeReusingHandle(): void
    {
        if (!\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::markTestSkipped('CURLOPT_XFERINFOFUNCTION is not available.');
        }

        $option = (int) \constant('CURLOPT_XFERINFOFUNCTION');
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
        ]);

        $factory->release($easy);

        self::assertArrayNotHasKey($option, $_SERVER['_curl']);
        self::assertCount(1, self::readIdleHandles($factory));
    }

    public function testEmitsDebugInfoToStream(): void
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

    public function testEmitsProgressToFunction(): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $called = [];
        $request = new Psr7\Request('HEAD', Server::$url);
        $response = $a($request, [
            'progress' => static function (...$args) use (&$called): void {
                $called[] = $args;
            },
        ]);
        $response->wait();
        self::assertNotEmpty($called);
        foreach ($called as $call) {
            self::assertCount(4, $call);
        }
    }

    private function addDecodeResponse(bool $withEncoding = true): string
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

    public function testDecodesGzippedResponses(): void
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

    public function testReportsOriginalSizeAndContentEncodingAfterDecoding(): void
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

    public function testDecodesGzippedResponsesWithHeader(): void
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

    /**
     * https://github.com/guzzle/guzzle/issues/2799
     */
    public function testDecodesGzippedResponsesWithHeaderForHeadRequest(): void
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

    public function testDoesNotForceDecode(): void
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

    public function testProtocolVersion(): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, [], null, '1.0');
        $a($request, []);
        self::assertEquals(\CURL_HTTP_VERSION_1_0, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
    }

    public function testRejectsEmptyProtocolVersion(): void
    {
        $factory = new CurlFactory(3);
        $request = self::requestWithProtocolVersion('');

        try {
            $factory->create($request, []);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertSame('HTTP protocol version must not be empty.', $e->getMessage());
        }
    }

    public function testRejectsMalformedProtocolVersion(): void
    {
        $factory = new CurlFactory(3);
        $request = self::requestWithProtocolVersion('HTTP/1.1');

        try {
            $factory->create($request, []);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertSame('HTTP protocol version must be a valid HTTP version number.', $e->getMessage());
        }
    }

    public function testThrowsWhenHttp2IsUnsupported(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::curlSslFeature(),
        ]);

        try {
            $factory = new CurlFactory(3);
            $request = new Psr7\Request('GET', Server::$url, [], null, '2.0');

            try {
                $factory->create($request, []);
                self::fail('Expected request exception.');
            } catch (RequestException $e) {
                self::assertSame($request, $e->getRequest());
                self::assertStringContainsString('HTTP/2 is supported by the cURL handler', $e->getMessage());
            }
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testThrowsWhenHttp3IsUnsupported(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::curlSslFeature(),
        ]);

        try {
            $factory = new CurlFactory(3);
            $request = new Psr7\Request('GET', Server::$url, [], null, '3.0');

            try {
                $factory->create($request, []);
                self::fail('Expected request exception.');
            } catch (RequestException $e) {
                self::assertSame($request, $e->getRequest());
                self::assertStringContainsString('HTTP/3 is supported by the cURL handler', $e->getMessage());
            }
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider http3ProtocolVersionProvider
     */
    public function testMapsHttp3ProtocolVersionToCurlOption(string $protocolVersion): void
    {
        if (!\defined('CURL_HTTP_VERSION_3')) {
            self::markTestSkipped('HTTP/3 cURL constants are not available.');
        }

        $conf = self::getDefaultCurlConf(
            new Psr7\Request('GET', 'https://example.com', [], null, $protocolVersion),
            []
        );

        self::assertSame((int) \constant('CURL_HTTP_VERSION_3'), $conf[\CURLOPT_HTTP_VERSION]);
    }

    public function testHttp3WithEffectiveProxyFallsBackToHttp2WhenSupported(): void
    {
        self::requireHttp3TestConstants();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::http3FeatureMask(true),
        ]);

        try {
            $factory = new CurlFactory(3);
            $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
                'proxy' => ['https' => 'http://proxy.example.com:8080'],
            ]);

            self::assertSame('http://proxy.example.com:8080', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
            self::assertSame(\CURL_HTTP_VERSION_2_0, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
            self::assertSame(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testHttp3WithStringProxyFallsBackToHttp2WhenSupported(): void
    {
        self::requireHttp3TestConstants();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::http3FeatureMask(true),
        ]);

        try {
            $factory = new CurlFactory(3);
            $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
                'proxy' => 'http://proxy.example.com:8080',
            ]);

            self::assertSame('http://proxy.example.com:8080', $_SERVER['_curl'][\CURLOPT_PROXY]);
            self::assertSame('', $_SERVER['_curl'][\CURLOPT_NOPROXY]);
            self::assertSame(\CURL_HTTP_VERSION_2_0, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testHttp3WithEffectiveProxyFallsBackToHttp11WhenHttp2IsUnsupported(): void
    {
        self::requireHttp3TestConstants();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::http3FeatureMask(false),
        ]);

        try {
            $factory = new CurlFactory(3);
            $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
                'proxy' => ['https' => 'http://proxy.example.com:8080'],
            ]);

            self::assertSame(\CURL_HTTP_VERSION_1_1, $_SERVER['_curl'][\CURLOPT_HTTP_VERSION]);
            self::assertSame(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testHttp3WithMatchingNoProxyKeepsHttp3(): void
    {
        self::requireHttp3TestConstants();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::http3FeatureMask(true),
        ]);

        try {
            $conf = self::getDefaultCurlConf(
                new Psr7\Request('GET', 'https://example.com', [], null, '3.0'),
                [
                    'proxy' => [
                        'https' => 'http://proxy.example.com:8080',
                        'no' => ['example.com'],
                    ],
                ]
            );

            self::assertSame((int) \constant('CURL_HTTP_VERSION_3'), $conf[\CURLOPT_HTTP_VERSION]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testHttp3WithEmptyProxyOptionKeepsHttp3(): void
    {
        self::requireHttp3TestConstants();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::http3FeatureMask(true),
        ]);

        try {
            $conf = self::getDefaultCurlConf(
                new Psr7\Request('GET', 'https://example.com', [], null, '3.0'),
                ['proxy' => '']
            );

            self::assertSame((int) \constant('CURL_HTTP_VERSION_3'), $conf[\CURLOPT_HTTP_VERSION]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testHttp3WithProxyStillRequiresHttp3SupportBeforeDowngrade(): void
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.66.0',
            'features' => self::curlSslFeature(),
        ]);

        try {
            $factory = new CurlFactory(3);
            $request = new Psr7\Request('GET', 'https://example.com', [], null, '3.0');

            try {
                $factory->create($request, [
                    'proxy' => ['https' => 'http://proxy.example.com:8080'],
                ]);
                self::fail('Expected request exception.');
            } catch (RequestException $e) {
                self::assertSame($request, $e->getRequest());
                self::assertStringContainsString('HTTP/3 is supported by the cURL handler', $e->getMessage());
            }
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider http3WeakCryptoMethodProvider
     */
    public function testHttp3UpgradesWeakCryptoMethodToTls12Minimum(int $cryptoMethod): void
    {
        if (!CurlVersion::supportsHttp3()) {
            self::markTestSkipped('HTTP/3 is not supported by this cURL installation.');
        }

        $factory = new CurlFactory(3);
        $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
            'crypto_method' => $cryptoMethod,
        ]);

        self::assertSame(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testHttp3PreservesExplicitTls13CryptoMethod(): void
    {
        if (!CurlVersion::supportsHttp3() || !CurlVersion::supportsTls13()) {
            self::markTestSkipped('HTTP/3 with explicit TLS 1.3 is not supported by this cURL installation.');
        }

        $factory = new CurlFactory(3);
        $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]);

        self::assertSame(\CURL_SSLVERSION_TLSv1_3, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testHttp3DefaultsHttpsToTls12Minimum(): void
    {
        if (!CurlVersion::supportsHttp3()) {
            self::markTestSkipped('HTTP/3 is not supported by this cURL installation.');
        }

        $factory = new CurlFactory(3);
        $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), []);

        self::assertSame(\CURL_SSLVERSION_TLSv1_2, $_SERVER['_curl'][\CURLOPT_SSLVERSION]);
    }

    public function testHttp3ValidatesCryptoMethodInvalidMethod(): void
    {
        if (!CurlVersion::supportsHttp3()) {
            self::markTestSkipped('HTTP/3 is not supported by this cURL installation.');
        }

        $factory = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method request option: unknown version provided');

        $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
            'crypto_method' => 123,
        ]);
    }

    public function testHttp3RejectsRawCurlSslVersionOption(): void
    {
        if (!CurlVersion::supportsHttp3()) {
            self::markTestSkipped('HTTP/3 is not supported by this cURL installation.');
        }

        $factory = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_SSLVERSION');

        $factory->create(new Psr7\Request('GET', 'https://example.com', [], null, '3.0'), [
            'curl' => [\CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2],
        ]);
    }

    public static function http3ProtocolVersionProvider(): array
    {
        return [
            ['3'],
            ['3.0'],
        ];
    }

    public static function http3WeakCryptoMethodProvider(): array
    {
        return [
            [\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT],
            [\STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT],
            [\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT],
        ];
    }

    public function testSavesToStream(): void
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

    public function testDoesNotCloseResourceSinkWhenResponseIsDestroyed(): void
    {
        $stream = (function () {
            $stream = \tmpfile();
            self::assertIsResource($stream);

            $this->addDecodeResponse();
            $handler = new Handler\CurlHandler();
            $request = new Psr7\Request('GET', Server::$url);
            $response = $handler($request, [
                'decode_content' => true,
                'sink' => $stream,
            ])->wait();

            self::assertSame(200, $response->getStatusCode());

            return $stream;
        })();

        \gc_collect_cycles();

        try {
            self::assertIsResource($stream);
            \rewind($stream);
            self::assertSame('test', \stream_get_contents($stream));
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    public function testDoesNotCloseResourceSinkWhenResponseBodyIsClosed(): void
    {
        $stream = \tmpfile();
        self::assertIsResource($stream);

        try {
            $this->addDecodeResponse();
            $handler = new Handler\CurlHandler();
            $request = new Psr7\Request('GET', Server::$url);
            $response = $handler($request, [
                'decode_content' => true,
                'sink' => $stream,
            ])->wait();

            $response->getBody()->close();

            self::assertIsResource($stream);
            \rewind($stream);
            self::assertSame('test', \stream_get_contents($stream));
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    public function testSavesToGuzzleStream(): void
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

    public function testSavesToFileOnDisk(): void
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

    public function testDoesNotAddMultipleContentLengthHeaders(): void
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

    public function testSendsPostWithNoBodyOrDefaultContentType(): void
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

    public function testFailsWhenCannotRewindRetryAfterNoResponse(): void
    {
        $factory = new CurlFactory(1);
        $stream = Psr7\Utils::streamFor('abc');
        $stream->read(1);
        $stream = new Psr7\NoSeekStream($stream);
        $request = new Psr7\Request('PUT', Server::$url, [], $stream);
        $fn = static function (RequestInterface $request, array $options) use (&$fn, $factory): P\PromiseInterface {
            $easy = $factory->create($request, $options);

            return CurlFactory::finish($fn, $easy, $factory);
        };

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('but attempting to rewind the request body failed');
        $fn($request, [])->wait();
    }

    public function testRetriesWhenBodyCanBeRewound(): void
    {
        $callHandler = $called = false;

        $fn = static function (RequestInterface $r, array $options) use (&$callHandler): P\PromiseInterface {
            $callHandler = true;

            return P\Create::promiseFor(new Psr7\Response());
        };

        $bd = Psr7\FnStream::decorate(Psr7\Utils::streamFor('test'), [
            'tell' => static function (): int {
                return 1;
            },
            'rewind' => static function () use (&$called): void {
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

    public function testFailsWhenRetryMoreThanThreeTimes(): void
    {
        $factory = new CurlFactory(1);
        $call = 0;
        $fn = static function (RequestInterface $request, array $options) use (&$mock, &$call, $factory): P\PromiseInterface {
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

    /**
     * Regression coverage for the CURLE_SEND_FAIL_REWIND (errno 65) arm of
     * shouldRetryFailedRewind()/retryFailedRewind(). libcurl returns errno 65
     * when it must rewind an already-partially-sent upload body (after a
     * redirect, multi-pass auth, or a dead reused connection) but cannot,
     * because PHP exposes no seek callback for a streamed request body
     * (https://bugs.php.net/bug.php?id=47204). Guzzle works around this by
     * rewinding the PSR-7 body itself and re-issuing the request.
     *
     * Until this commit the errno === 0 arm was covered
     * (testRetriesWhenBodyCanBeRewound, testFailsWhenRetryMoreThanThreeTimes)
     * but the errno === 65 arm had none, so a regression in it would have
     * passed CI unnoticed.
     *
     * This test and testFailsAfterThreeRetriesOnFailedRewindErrno may be
     * removed once Guzzle registers a CURLOPT_SEEKFUNCTION for streamed bodies
     * so libcurl can rewind natively and never surfaces errno 65 for a seekable
     * body. That requires PHP to expose CURLOPT_SEEKFUNCTION (it does not as of
     * PHP 8.4) and a minimum PHP/libcurl version that includes it.
     */
    public function testRetriesWhenCurlReportsFailedRewindErrno(): void
    {
        $rewound = false;
        $handlerCalled = false;

        $handler = static function (RequestInterface $request, array $options) use (&$handlerCalled): P\PromiseInterface {
            $handlerCalled = true;

            return P\Create::promiseFor(new Psr7\Response());
        };

        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('test'), [
            'tell' => static function (): int {
                return 1;
            },
            'rewind' => static function () use (&$rewound): void {
                $rewound = true;
            },
        ]);

        $factory = new CurlFactory(1);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);
        $easy = $factory->create($request, []);
        // Reset the flag so the assertion below observes the retry's rewind,
        // not the rewind applyBody() performs while creating the handle.
        $rewound = false;
        // Simulate libcurl returning errno 65 (failed rewind) and no response.
        $easy->errno = 65;
        $easy->response = null;

        $response = CurlFactory::finish($handler, $easy, $factory)->wait();

        self::assertTrue($rewound, 'The request body should have been rewound before retrying');
        self::assertTrue($handlerCalled, 'The request should have been retried');
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Companion to testRetriesWhenCurlReportsFailedRewindErrno: when libcurl
     * keeps reporting CURLE_SEND_FAIL_REWIND (errno 65) the retry is bounded to
     * three attempts before giving up, mirroring
     * testFailsWhenRetryMoreThanThreeTimes for the errno === 0 arm. See that
     * test's docblock for the removal conditions that apply to both errno-65
     * tests.
     */
    public function testFailsAfterThreeRetriesOnFailedRewindErrno(): void
    {
        $factory = new CurlFactory(1);
        $calls = 0;
        $handler = static function (RequestInterface $request, array $options) use (&$mock, &$calls, $factory): P\PromiseInterface {
            ++$calls;
            $easy = $factory->create($request, $options);
            // Each attempt reports a failed rewind (errno 65) with no response.
            $easy->errno = 65;
            $easy->response = null;

            return CurlFactory::finish($mock, $easy, $factory);
        };
        $mock = new Handler\MockHandler([$handler, $handler, $handler]);
        $promise = $mock(new Psr7\Request('PUT', Server::$url, [], 'test'), []);
        $promise->wait(false);
        self::assertSame(3, $calls);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The cURL request was retried 3 times');
        $promise->wait(true);
    }

    public function testHandles100Continue(): void
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

    public static function curlConnectionErrorProvider(): iterable
    {
        yield 'resolve proxy' => [5];
        yield 'resolve host' => [6];
        yield 'connect' => [7];
        yield 'ssl connect' => [35];
        yield 'old peer verification' => [51];
        yield 'ssl cacert / peer verification' => [60];
        yield 'ssl issuer' => [83];
        yield 'ssl pinned public key mismatch' => [90];
        yield 'ssl invalid cert status' => [91];
        yield 'quic connect' => [96];
        yield 'proxy handshake' => [97];
        yield 'ssl client cert' => [98];
        yield 'ech required' => [101];
    }

    /**
     * @dataProvider curlConnectionErrorProvider
     */
    public function testCreatesConnectExceptionForConnectionErrors(int $errno): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->errno = $errno;
        $response = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $response->wait();
            self::fail('Expected ConnectException');
        } catch (ConnectTimeoutException $e) {
            self::fail('Expected non-timeout ConnectException');
        } catch (ConnectException $e) {
            self::assertSame($request, $e->getRequest());
        }
    }

    public static function curlNetworkErrorWithoutResponseProvider(): iterable
    {
        yield 'http2 framing' => [16];
        yield 'got nothing' => [52];
        yield 'send' => [55];
        yield 'receive' => [56];
        yield 'http2 stream' => [92];
        yield 'http3' => [95];
    }

    /**
     * @dataProvider curlNetworkErrorWithoutResponseProvider
     */
    public function testCreatesNetworkExceptionForNetworkErrorsWithoutResponse(int $errno): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->errno = $errno;
        $easy->response = null;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected NetworkException');
        } catch (ConnectTimeoutException $e) {
            self::fail('Expected non-timeout NetworkException');
        } catch (NetworkTimeoutException $e) {
            self::fail('Expected non-timeout NetworkException');
        } catch (NetworkException $e) {
            self::assertNotInstanceOf(ConnectException::class, $e);
            self::assertSame($request, $e->getRequest());
        }
    }

    public function testInterimResponseFollowedByEmptyReplyIsNetworkException(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->headers = ['HTTP/1.1 103 Early Hints', 'Link: </a.css>; rel=preload'];
        $easy->createResponse();
        self::assertNull($easy->response);
        $easy->errno = \CURLE_GOT_NOTHING;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected NetworkException');
        } catch (NetworkException $e) {
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
        }
    }

    public function testInterimResponseFollowedByRecvErrorIsNetworkException(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->headers = ['HTTP/1.1 100 Continue'];
        $easy->createResponse();
        self::assertNull($easy->response);
        $easy->errno = \CURLE_RECV_ERROR;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected NetworkException');
        } catch (NetworkException $e) {
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
        }
    }

    /**
     * @dataProvider curlNetworkErrorWithoutResponseProvider
     */
    public function testNetworkErrorsWithResponseCreateResponseTransferExceptions(int $errno): void
    {
        $this->assertCurlErrorWithResponseCreatesResponseTransferException($errno);
    }

    /**
     * @dataProvider curlConnectionErrorProvider
     */
    public function testConnectionErrorsWithResponseCreateResponseTransferExceptions(int $errno): void
    {
        $this->assertCurlErrorWithResponseCreatesResponseTransferException($errno);
    }

    private function assertCurlErrorWithResponseCreatesResponseTransferException(int $errno): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $easy->errno = $errno;
        $easy->response = $response;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
        }
    }

    /**
     * @dataProvider curlResponseTransferErrorProvider
     */
    public function testResponseTransferCurlErrorsWithResponseCreateResponseTransferExceptions(int $errno): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $easy->errno = $errno;
        $easy->response = $response;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
        }
    }

    public function testUnrepresentableResponseContentLengthCreatesResponseException(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $stats = null;
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);
        $overflow = ((string) \PHP_INT_MAX).'0';

        $header = self::receiveCurlHeaders($easy, [
            "HTTP/1.1 200 OK\r\n",
            "Content-Length: {$overflow}\r\n",
        ]);
        self::assertSame(-1, $header($easy->handle, "\r\n"));
        $easy->errno = \CURLE_WRITE_ERROR;

        try {
            self::finishEasy($easy, $factory);

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Content-Length exceeds the maximum integer size supported on this platform', $e->getMessage());
            self::assertInstanceOf(\OverflowException::class, $e->getPrevious());
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertInstanceOf(\OverflowException::class, $stats->getHandlerErrorData());
    }

    public function testOnHeadersExceptionWinsOverUnrepresentableResponseContentLength(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $previous = new \RuntimeException('on headers failed');
        $easy = $factory->create($request, [
            'on_headers' => static function () use ($previous): void {
                throw $previous;
            },
        ]);
        $overflow = ((string) \PHP_INT_MAX).'0';

        $header = self::receiveCurlHeaders($easy, [
            "HTTP/1.1 200 OK\r\n",
            "Content-Length: {$overflow}\r\n",
        ]);
        self::assertSame(-1, $header($easy->handle, "\r\n"));
        $easy->errno = \CURLE_WRITE_ERROR;

        try {
            self::finishEasy($easy, $factory);

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame('An error was encountered during the on_headers event', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
        }
    }

    public function testResponseBodyByteCountOverflowCreatesResponseException(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $write = $_SERVER['_curl'][\CURLOPT_WRITEFUNCTION];
        $easy->response = $response;
        $easy->responseBodyBytes = \PHP_INT_MAX - 1;

        self::assertSame(0, $write($easy->handle, 'ab'));
        self::assertInstanceOf(\OverflowException::class, $easy->responseBodySizeException);

        try {
            self::finishEasy($easy, $factory);

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
            self::assertSame($easy->responseBodySizeException, $e->getPrevious());
        }
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

    private static function finishEasy(EasyHandle $easy, CurlFactory $factory): void
    {
        CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        )->wait();
    }

    public static function curlResponseTransferErrorProvider(): iterable
    {
        yield 'partial file' => [18];
        yield 'bad content encoding' => [61];
    }

    /**
     * @dataProvider localCurlErrorWithResponseProvider
     */
    public function testLocalCurlErrorsWithResponseStayResponseExceptions(int $errno): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $easy->errno = $errno;
        $easy->response = $response;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
        }
    }

    public static function localCurlErrorWithResponseProvider(): iterable
    {
        yield 'write' => [23];
        yield 'file size exceeded' => [63];
    }

    public function testCallbackAbortWithResponseStaysResponseException(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;
        $easy->response = $response;

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
        }
    }

    public function testCreatesNetworkTimeoutExceptionForNonConnectTimeout(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->errno = \CURLE_OPERATION_TIMEOUTED;
        $response = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $response->wait();
            self::fail('Expected NetworkTimeoutException');
        } catch (ConnectTimeoutException $e) {
            self::fail('Expected NetworkTimeoutException, not ConnectTimeoutException');
        } catch (NetworkTimeoutException $e) {
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertNotInstanceOf(ConnectException::class, $e);
            self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
        }
    }

    public function testCreatesConnectTimeoutExceptionForConnectTimeout(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->errno = \CURLE_OPERATION_TIMEOUTED;
        $easy->response = null;
        $promise = $this->createCurlRejection($easy, [
            'errno' => \CURLE_OPERATION_TIMEOUTED,
            'error' => 'Connection timeout after 5003 ms',
        ]);

        try {
            $promise->wait();
            self::fail('Expected ConnectTimeoutException');
        } catch (ConnectTimeoutException $e) {
            self::assertInstanceOf(ConnectException::class, $e);
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertNotInstanceOf(RequestExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
        }
    }

    public function testCreatesNetworkTimeoutExceptionForGenericCurlTimeoutMessage(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $easy->errno = \CURLE_OPERATION_TIMEOUTED;
        $easy->response = null;
        $promise = $this->createCurlRejection($easy, [
            'errno' => \CURLE_OPERATION_TIMEOUTED,
            'error' => 'Timeout was reached',
        ]);

        try {
            $promise->wait();
            self::fail('Expected NetworkTimeoutException');
        } catch (ConnectTimeoutException $e) {
            self::fail('Expected NetworkTimeoutException, not ConnectTimeoutException');
        } catch (NetworkTimeoutException $e) {
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertNotInstanceOf(ConnectException::class, $e);
            self::assertSame($request, $e->getRequest());
        }
    }

    public function testClassifiesConnectTimeoutErrors(): void
    {
        self::assertTrue($this->matchesCurlConnectTimeoutError('Connection timed out after 5003 milliseconds'));
        self::assertTrue($this->matchesCurlConnectTimeoutError('Connection timeout after 5003 ms'));
        self::assertTrue($this->matchesCurlConnectTimeoutError('Connection time-out'));
        self::assertTrue($this->matchesCurlConnectTimeoutError('Resolving timed out after 5000 milliseconds'));
        self::assertTrue($this->matchesCurlConnectTimeoutError("Failed to resolve 'example.com' with timeout after 1000 ms"));
        self::assertTrue($this->matchesCurlConnectTimeoutError('name lookup timed out'));
        self::assertTrue($this->matchesCurlConnectTimeoutError('Proxy CONNECT aborted due to timeout'));
        self::assertTrue($this->matchesCurlConnectTimeoutError('SSL connection timeout'));
        self::assertFalse($this->matchesCurlConnectTimeoutError('Operation timed out after 30000 milliseconds with 0 bytes received'));
        self::assertFalse($this->matchesCurlConnectTimeoutError('Operation too slow. Less than 10 bytes/sec transferred the last 30 seconds'));
        self::assertFalse($this->matchesCurlConnectTimeoutError('Timeout was reached'));
        self::assertFalse($this->matchesCurlConnectTimeoutError(''));
    }

    private function matchesCurlConnectTimeoutError(string $error): bool
    {
        $reflection = new \ReflectionMethod(CurlFactory::class, 'isConnectTimeoutError');
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->invoke(null, $error) === true;
    }

    public function testCreatesResponseTimeoutExceptionWithResponse(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $easy->errno = \CURLE_OPERATION_TIMEOUTED;
        $easy->response = $response;
        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        try {
            $promise->wait();
            self::fail('Expected ResponseTimeoutException');
        } catch (ResponseTimeoutException $e) {
            self::assertInstanceOf(ResponseException::class, $e);
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
        }
    }

    public function testResponseTimeoutWinsOverConnectTimeoutLookingCurlMessage(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200);
        $easy = $factory->create($request, []);
        $easy->errno = \CURLE_OPERATION_TIMEOUTED;
        $easy->response = $response;
        $promise = $this->createCurlRejection($easy, [
            'errno' => \CURLE_OPERATION_TIMEOUTED,
            'error' => 'Connection timed out after 5003 milliseconds',
        ]);

        try {
            $promise->wait();
            self::fail('Expected ResponseTimeoutException');
        } catch (ResponseTimeoutException $e) {
            self::assertInstanceOf(ResponseException::class, $e);
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
        }
    }

    private function createCurlRejection(EasyHandle $easy, array $ctx)
    {
        $reflection = new \ReflectionMethod(CurlFactory::class, 'createRejection');
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->invoke(null, $easy, $ctx);
    }

    private static function assertResponseInfoWasNotExposed(array $context): void
    {
        self::assertArrayNotHasKey('http_code', $context);
        self::assertArrayNotHasKey('header_size', $context);
        self::assertArrayNotHasKey('content_type', $context);
    }

    public function testAddsTimeouts(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'timeout' => 0.1,
            'connect_timeout' => 0.2,
        ]);
        self::assertEquals(100, $_SERVER['_curl'][\CURLOPT_TIMEOUT_MS]);
        self::assertEquals(200, $_SERVER['_curl'][\CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testAddsZeroTimeouts(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'timeout' => 0,
            'connect_timeout' => 0,
        ]);
        self::assertSame(0, $_SERVER['_curl'][\CURLOPT_TIMEOUT_MS]);
        self::assertSame(0, $_SERVER['_curl'][\CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testTruncatesTimeoutsToMilliseconds(): void
    {
        $f = new CurlFactory(3);
        $f->create(new Psr7\Request('GET', Server::$url), [
            'timeout' => 0.0015,
            'connect_timeout' => 0.0025,
        ]);
        self::assertSame(1, $_SERVER['_curl'][\CURLOPT_TIMEOUT_MS]);
        self::assertSame(2, $_SERVER['_curl'][\CURLOPT_CONNECTTIMEOUT_MS]);
    }

    /**
     * @dataProvider invalidCurlTimeoutProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidCurlTimeouts(string $option, $value): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' must be 0 or greater than or equal to 0.001 seconds');
        $f->create(new Psr7\Request('GET', Server::$url), [$option => $value]);
    }

    public static function invalidCurlTimeoutProvider(): array
    {
        return [
            ['timeout', 0.0001],
            ['timeout', -1],
            ['connect_timeout', 0.0001],
            ['connect_timeout', -1],
        ];
    }

    public function testAddsStreamingBody(): void
    {
        $f = new CurlFactory(3);
        $request = new Psr7\Request('PUT', Server::$url, ['Content-Length' => '1000000'], 'foo');
        $f->create($request, []);
        self::assertEquals(1, $_SERVER['_curl'][\CURLOPT_UPLOAD]);
        self::assertIsCallable($_SERVER['_curl'][\CURLOPT_READFUNCTION]);
    }

    /**
     * @dataProvider validCurlRequestContentLengthProvider
     *
     * @param string|string[] $contentLength
     */
    public function testUsesParsedRequestContentLengthForCurlUpload($contentLength): void
    {
        $factory = new CurlFactory(3);
        $request = new Psr7\Request('PUT', Server::$url, [
            'Content-Length' => $contentLength,
        ], 'foo');

        $factory->create($request, []);

        self::assertTrue((bool) $_SERVER['_curl'][\CURLOPT_UPLOAD]);
        self::assertSame(1000000, $_SERVER['_curl'][\CURLOPT_INFILESIZE]);
    }

    public static function validCurlRequestContentLengthProvider(): iterable
    {
        return [
            'plain' => ['1000000'],
            'leading zeros' => ['001000000'],
            'comma equivalent' => ['001000000, 1000000'],
            'duplicate equivalent' => [['001000000', '1000000']],
        ];
    }

    public function testNormalizesEquivalentRequestContentLengthForCurlHeaders(): void
    {
        $factory = new CurlFactory(3);
        $request = new Psr7\Request('GET', Server::$url, [
            'Content-Length' => ['0003', '3'],
        ]);

        $factory->create($request, []);

        self::assertContains('Content-Length: 3', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
        self::assertNotContains('Content-Length: 0003', $_SERVER['_curl'][\CURLOPT_HTTPHEADER]);
    }

    /**
     * @dataProvider invalidCurlRequestContentLengthProvider
     *
     * @param string|string[] $contentLength
     */
    public function testRejectsInvalidCurlRequestContentLength($contentLength): void
    {
        $factory = new CurlFactory(3);
        $request = new Psr7\Request('PUT', Server::$url, [
            'Content-Length' => $contentLength,
        ], 'foo');

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid Content-Length request header');

        $factory->create($request, []);
    }

    public static function invalidCurlRequestContentLengthProvider(): iterable
    {
        return [
            'empty' => [''],
            'empty comma member' => ['3,'],
            'non digit' => ['abc'],
            'partial numeric' => ['3abc'],
            'signed' => ['-1'],
            'decimal' => ['3.0'],
            'conflicting comma' => ['3, 5'],
            'conflicting duplicate' => [['3', '5']],
        ];
    }

    public function testRejectsUnrepresentableRequestContentLengthForCurlUpload(): void
    {
        $factory = new CurlFactory(3);
        $length = ((string) \PHP_INT_MAX).'0';
        $request = new Psr7\Request('PUT', Server::$url, [
            'Content-Length' => $length,
        ], 'foo');

        try {
            $factory->create($request, []);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('Content-Length exceeds the maximum integer size supported on this platform', $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(\OverflowException::class, $e->getPrevious());
        }
    }

    public function testRejectsInvalidCurlRequestContentLengthWithEmptyBody(): void
    {
        $factory = new CurlFactory(3);
        $request = new Psr7\Request('POST', Server::$url, [
            'Content-Length' => 'abc',
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid Content-Length request header');

        $factory->create($request, []);
    }

    public function testEnsuresDirExistsBeforeThrowingWarning(): void
    {
        $f = new CurlFactory(3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory /does/not/exist/so does not exist for sink value of /does/not/exist/so/error.txt');
        $f->create(new Psr7\Request('GET', Server::$url), [
            'sink' => '/does/not/exist/so/error.txt',
        ]);
    }

    public function testClosesIdleHandles(): void
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

    public function testRejectsPromiseWhenCreateResponseFails(): void
    {
        Server::flush();
        Server::enqueueRaw(999, 'Incorrect', ['X-Foo' => 'bar'], 'abc 123');

        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
        $called = false;
        $stats = null;
        $promise = $handler($req, [
            'on_headers' => static function () use (&$called): void {
                $called = true;
            },
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
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
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertFalse($stats->hasResponse());
            self::assertNull($stats->getResponse());
            self::assertResponseInfoWasNotExposed($stats->getHandlerStats());
        }
    }

    public function testCreateResponseFailureDoesNotExposeStaleCurlResponse(): void
    {
        $factory = new CurlFactory(1);
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), []);
        $easy->response = new Psr7\Response(100);
        $easy->errno = \CURLE_WRITE_ERROR;
        $easy->createResponseException = new \InvalidArgumentException(
            'Status code must be an integer value between 1xx and 5xx.'
        );

        $promise = CurlFactory::finish(
            static function (): void {
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
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertSame($easy->createResponseException, $e->getPrevious());
        }
    }

    public function testEnsuresOnHeadersIsCallable(): void
    {
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();

        $this->expectException(\InvalidArgumentException::class);
        $handler($req, ['on_headers' => 'error!']);
    }

    public function testIgnoresInterim1xxAndInvokesOnHeadersOnceForFinalResponse(): void
    {
        $factory = new CurlFactory(1);
        $statuses = [];
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_headers' => static function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
        ]);

        try {
            /** @var callable $headerFn */
            $headerFn = $_SERVER['_curl'][\CURLOPT_HEADERFUNCTION];

            $headerFn($easy->handle, "HTTP/1.1 103 Early Hints\r\n");
            $headerFn($easy->handle, "Link: </style.css>; rel=preload\r\n");
            $headerFn($easy->handle, "\r\n");

            self::assertNull($easy->response, 'interim 1xx is not stored');
            self::assertSame([], $statuses, 'on_headers is not invoked for an interim 1xx');

            $headerFn($easy->handle, "HTTP/1.1 200 OK\r\n");
            $headerFn($easy->handle, "Content-Length: 0\r\n");
            $headerFn($easy->handle, "\r\n");

            self::assertNotNull($easy->response);
            self::assertSame(200, $easy->response->getStatusCode());
            self::assertSame([200], $statuses, 'on_headers fires once, for the final response');
        } finally {
            if (\array_key_exists('handle', \get_object_vars($easy))) {
                $factory->release($easy);
            }
        }
    }

    public function testKeeps101AndInvokesOnHeaders(): void
    {
        $factory = new CurlFactory(1);
        $statuses = [];
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_headers' => static function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
        ]);

        try {
            /** @var callable $headerFn */
            $headerFn = $_SERVER['_curl'][\CURLOPT_HEADERFUNCTION];

            $headerFn($easy->handle, "HTTP/1.1 101 Switching Protocols\r\n");
            $headerFn($easy->handle, "Upgrade: websocket\r\n");
            $headerFn($easy->handle, "\r\n");

            self::assertNotNull($easy->response, '101 is kept as a response');
            self::assertSame(101, $easy->response->getStatusCode());
            self::assertSame([101], $statuses, 'on_headers fires for a 101 response');
        } finally {
            if (\array_key_exists('handle', \get_object_vars($easy))) {
                $factory->release($easy);
            }
        }
    }

    public function testRejectsPromiseWhenOnHeadersFails(): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $handler = new Handler\CurlHandler();
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

    public function testInvokesOnStatsWhenOnHeadersFails(): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $gotStats = null;
        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'on_headers' => static function (): void {
                throw new \RuntimeException('test');
            },
            'on_stats' => static function (TransferStats $stats) use (&$gotStats): void {
                $gotStats = $stats;
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString('An error was encountered during the on_headers event', $e->getMessage());
            self::assertInstanceOf(TransferStats::class, $gotStats);
            self::assertTrue($gotStats->hasResponse());
            self::assertSame(200, $gotStats->getResponse()->getStatusCode());
            self::assertSame($req, $gotStats->getRequest());
            self::assertSame(Server::$url, (string) $gotStats->getEffectiveUri());
            self::assertIsInt($gotStats->getHandlerErrorData());
        }
    }

    public function testSuccessfullyCallsOnHeadersBeforeWritingToSink(): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Psr7\Request('GET', Server::$url);
        $got = null;
        $gotRequest = null;

        $stream = Psr7\Utils::streamFor();
        $stream = Psr7\FnStream::decorate($stream, [
            'write' => static function (string $data) use ($stream, &$got): int {
                self::assertNotNull($got);

                return $stream->write($data);
            },
        ]);

        $handler = new Handler\CurlHandler();
        $promise = $handler($req, [
            'sink' => $stream,
            'on_headers' => static function (
                ResponseInterface $res,
                RequestInterface $request
            ) use (&$got, &$gotRequest, $req): void {
                $got = $res;
                $gotRequest = $request;
                self::assertSame($req, $request);
                self::assertEquals('bar', $res->getHeaderLine('X-Foo'));
            },
        ]);

        $response = $promise->wait();
        self::assertSame($req, $gotRequest);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('abc 123', (string) $response->getBody());
    }

    public function testStreamingRequestBodyReadPsr7TimeoutAbortsReadCallback(): void
    {
        $factory = new CurlFactory(3);
        $previous = new Psr7\Exception\TimeoutException('Unable to read from stream: timed out');
        $readCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'read' => static function (int $length) use (&$readCalled, $previous): string {
                $readCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);
        $easy = $factory->create($request, []);

        try {
            $callback = $_SERVER['_curl'][\CURLOPT_READFUNCTION];

            self::assertSame(0x10000000, $callback($easy->handle, null, 8192));
            self::assertTrue($readCalled);
            self::assertSame($previous, $easy->bodyReadTimeoutException);
        } finally {
            if (\array_key_exists('handle', \get_object_vars($easy))) {
                $factory->release($easy);
            }
        }
    }

    public function testStreamingRequestBodyReadFailureAbortsReadCallback(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Error('boom while reading');
        $readCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'read' => static function (int $length) use (&$readCalled, $previous): string {
                $readCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);
        $easy = $factory->create($request, []);

        try {
            $callback = $_SERVER['_curl'][\CURLOPT_READFUNCTION];

            self::assertSame(0x10000000, $callback($easy->handle, null, 8192));
            self::assertTrue($readCalled);
            self::assertSame($previous, $easy->bodyReadException);
            self::assertNull($easy->bodyReadTimeoutException);
        } finally {
            if (\array_key_exists('handle', \get_object_vars($easy))) {
                $factory->release($easy);
            }
        }
    }

    public function testStreamingUploadInstallsProgressAbortForBodyReadTimeout(): void
    {
        $factory = new CurlFactory(3);
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);
        $easy = $factory->create($request, []);

        try {
            // A progress callback is installed for streaming uploads even when
            // the "progress" option is absent, so the upload can be aborted on a
            // body read timeout on PHP versions where the read callback's abort
            // return value is ignored (< 8.1.17 / < 8.2.4).
            self::assertArrayHasKey(self::progressCallbackOption(), $_SERVER['_curl']);
            $progress = $_SERVER['_curl'][self::progressCallbackOption()];

            self::assertSame(0, $progress($easy->handle, 0, 0, 0, 0));

            $easy->bodyReadTimeoutException = new Psr7\Exception\TimeoutException('Unable to read from stream: timed out');
            self::assertSame(1, $progress($easy->handle, 0, 0, 0, 0));
            self::assertFalse($easy->progressAborted);
        } finally {
            if (\array_key_exists('handle', \get_object_vars($easy))) {
                $factory->release($easy);
            }
        }
    }

    public function testStreamingUploadInstallsProgressAbortForBodyReadFailure(): void
    {
        $factory = new CurlFactory(3);
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);
        $easy = $factory->create($request, []);

        try {
            self::assertArrayHasKey(self::progressCallbackOption(), $_SERVER['_curl']);
            $progress = $_SERVER['_curl'][self::progressCallbackOption()];

            self::assertSame(0, $progress($easy->handle, 0, 0, 0, 0));

            $easy->bodyReadException = new \RuntimeException('boom while reading');
            self::assertSame(1, $progress($easy->handle, 0, 0, 0, 0));
            self::assertFalse($easy->progressAborted);
        } finally {
            if (\array_key_exists('handle', \get_object_vars($easy))) {
                $factory->release($easy);
            }
        }
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testStreamingRequestBodyReadTimeoutAbortsTransferThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [], 'hi'),
        ]);
        $handler = $handlerFactory();
        $previous = new Psr7\Exception\TimeoutException('Unable to read from stream: timed out');
        $readCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'read' => static function (int $length) use (&$readCalled, $previous): string {
                $readCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $handler($request, [])->wait();

            self::fail('Expected the upload read timeout to reject the transfer');
        } catch (ResponseException $e) {
            // PHP versions without read-callback abort support (< 8.1.17, and
            // 8.2.0-8.2.3 since the fix shipped in 8.1.17 and 8.2.4) ignore the
            // read callback's abort return, so the transfer is aborted by the
            // progress callback, possibly after an early response is observed.
            // It is still a timeout, never a silent success.
            self::assertTrue(\PHP_VERSION_ID < 80117 || (\PHP_VERSION_ID >= 80200 && \PHP_VERSION_ID < 80204));
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while reading the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        } catch (RequestException $e) {
            // On PHP >= 8.1.17 / 8.2.4 the read callback aborts synchronously
            // (and on older PHP the progress callback may abort before a
            // response arrives), so no usable response is received.
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while reading the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        self::assertTrue($readCalled);
    }

    public function testStreamingRequestBodyReadPsr7TimeoutRejectsAsRequestExceptionWithoutResponse(): void
    {
        $factory = new CurlFactory(3);
        $previous = new Psr7\Exception\TimeoutException('Unable to read from stream: timed out');
        $stats = null;
        $request = new Psr7\Request('PUT', Server::$url, [], 'payload');
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);
        $easy->bodyReadTimeoutException = $previous;
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;
        $handler = static function (RequestInterface $request, array $options) {
            self::fail('Did not expect timeout failure to be retried');

            return P\Create::promiseFor(new Psr7\Response());
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while reading the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertFalse($stats->hasResponse());
        self::assertNull($stats->getResponse());
        self::assertSame($request, $stats->getRequest());
        self::assertSame(\CURLE_ABORTED_BY_CALLBACK, $stats->getHandlerErrorData());
    }

    public function testRequestBodyGetSizeTimeoutRejectsAsRequestException(): void
    {
        $factory = new CurlFactory(3);
        $previous = new Psr7\Exception\TimeoutException('Unable to determine stream size: timed out');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function () use ($previous): ?int {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, []);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while determining the request body size', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }
    }

    public function testRequestBodyGetSizeFailureUsesFallbackMessageWhenMessageEmpty(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \RuntimeException('');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function () use ($previous): ?int {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, []);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Failed to determine the request body size', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testRequestBodyReadFailureRejectsAsRequestExceptionWithoutResponseAndWithoutRetry(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Exception('boom while reading');
        $stats = null;
        $request = new Psr7\Request('PUT', Server::$url, [], 'payload');
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);
        $easy->bodyReadException = $previous;
        $easy->errno = 0;
        $retried = false;
        $handler = static function () use (&$retried): P\PromiseInterface {
            $retried = true;

            return P\Create::promiseFor(new Psr7\Response());
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('boom while reading', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertFalse($retried);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertFalse($stats->hasResponse());
        self::assertNull($stats->getResponse());
        self::assertSame($request, $stats->getRequest());
        self::assertSame(0, $stats->getHandlerErrorData());
    }

    public function testStreamingRequestBodyReadPsr7TimeoutRejectsAsResponseExceptionWithResponse(): void
    {
        $factory = new CurlFactory(3);
        $previous = new Psr7\Exception\TimeoutException('Unable to read from stream: timed out');
        $stats = null;
        $request = new Psr7\Request('PUT', Server::$url, [], 'payload');
        $response = new Psr7\Response(200, [], 'early');
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);
        $easy->response = $response;
        $easy->bodyReadTimeoutException = $previous;
        $easy->errno = \CURLE_ABORTED_BY_CALLBACK;
        $handler = static function (RequestInterface $request, array $options) {
            self::fail('Did not expect timeout failure to be retried');

            return P\Create::promiseFor(new Psr7\Response());
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
            self::assertSame('Timed out while reading the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($response, $stats->getResponse());
        self::assertSame($request, $stats->getRequest());
        self::assertSame(\CURLE_ABORTED_BY_CALLBACK, $stats->getHandlerErrorData());
    }

    public function testRequestBodyReadFailureRejectsAsResponseExceptionWithResponseAndErrnoZero(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Exception('boom while reading');
        $stats = null;
        $request = new Psr7\Request('PUT', Server::$url, [], 'payload');
        $response = new Psr7\Response(200, [], 'early');
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);
        $easy->response = $response;
        $easy->bodyReadException = $previous;
        $easy->errno = 0;
        $retried = false;
        $handler = static function () use (&$retried): P\PromiseInterface {
            $retried = true;

            return P\Create::promiseFor(new Psr7\Response());
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
            self::assertSame('boom while reading', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertFalse($retried);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($response, $stats->getResponse());
        self::assertSame($request, $stats->getRequest());
        self::assertSame(0, $stats->getHandlerErrorData());
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testBodyAsStringRequestBodyReadPsr7TimeoutRejectsAsRequestExceptionThroughCurlHandlers(callable $handlerFactory): void
    {
        $previous = new Psr7\Exception\TimeoutException('Unable to read from stream: timed out');
        $castCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            '__toString' => static function () use (&$castCalled, $previous): string {
                $castCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);
        $handler = $handlerFactory();

        try {
            $handler($request, [
                'curl' => ['body_as_string' => true],
            ])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while reading the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        } finally {
            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        self::assertTrue($castCalled);
    }

    public function testBodyAsStringRequestBodyReadFailureUsesFallbackMessageWhenMessageEmpty(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \RuntimeException('');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            '__toString' => static function () use ($previous): string {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, [
                'curl' => ['body_as_string' => true],
            ]);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Failed to read the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testBodyAsStringRequestBodyReadFailureRejectsAsRequestException(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Exception('boom while reading');
        $castCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            '__toString' => static function () use (&$castCalled, $previous): string {
                $castCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, [
                'curl' => ['body_as_string' => true],
            ]);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('boom while reading', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertTrue($castCalled);
    }

    public function testBodyAsStringRequestBodyReadErrorPropagates(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Error('boom while reading');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            '__toString' => static function () use ($previous): string {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, [
                'curl' => ['body_as_string' => true],
            ]);
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testStreamingRequestBodyRewindFailureRejectsAsRequestException(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Exception('boom while rewinding');
        $rewindCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'rewind' => static function () use (&$rewindCalled, $previous): void {
                $rewindCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, []);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('boom while rewinding', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertTrue($rewindCalled);
    }

    public function testStreamingRequestBodyRewindErrorPropagates(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \Error('boom while rewinding');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'rewind' => static function () use ($previous): void {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, []);
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testStreamingRequestBodyRewindTimeoutRejectsAsRequestException(): void
    {
        $factory = new CurlFactory(3);
        $previous = new Psr7\Exception\TimeoutException('Unable to rewind stream: timed out');
        $rewindCalled = false;
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'rewind' => static function () use (&$rewindCalled, $previous): void {
                $rewindCalled = true;

                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, []);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while rewinding the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertTrue($rewindCalled);
    }

    public function testStreamingRequestBodyRewindFailureUsesFallbackMessageWhenMessageEmpty(): void
    {
        $factory = new CurlFactory(3);
        $previous = new \RuntimeException('');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                return null;
            },
            'rewind' => static function () use ($previous): void {
                throw $previous;
            },
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $body);

        try {
            $factory->create($request, []);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Failed to rewind the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testSinkWritePsr7TimeoutRejectsAsResponseExceptionThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [], 'abc'),
        ]);
        $request = new Psr7\Request('GET', Server::$url);
        $handler = $handlerFactory();
        $previous = new Psr7\Exception\TimeoutException('Unable to write to stream: timed out');
        $stats = null;
        $writeCalled = false;
        $sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use (&$writeCalled, $previous): int {
                $writeCalled = true;

                throw $previous;
            },
        ]);

        try {
            $handler($request, [
                'sink' => $sink,
                'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                    $stats = $transferStats;
                },
            ])->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Timed out while writing the response body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        self::assertTrue($writeCalled);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame(200, $stats->getResponse()->getStatusCode());
        self::assertSame(\CURLE_WRITE_ERROR, $stats->getHandlerErrorData());
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testGenericSinkWriteFailureRejectsAsResponseExceptionThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [], 'abc'),
        ]);
        $request = new Psr7\Request('GET', Server::$url);
        $handler = $handlerFactory();
        $previous = new \RuntimeException('sink failed');
        $stats = null;
        $writeCalled = false;
        $sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use (&$writeCalled, $previous): int {
                $writeCalled = true;

                throw $previous;
            },
        ]);

        try {
            $handler($request, [
                'sink' => $sink,
                'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                    $stats = $transferStats;
                },
            ])->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('sink failed', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        self::assertTrue($writeCalled);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame(200, $stats->getResponse()->getStatusCode());
        self::assertSame(\CURLE_WRITE_ERROR, $stats->getHandlerErrorData());
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testSinkWriteErrorRejectsAsResponseExceptionThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [], 'abc'),
        ]);
        $request = new Psr7\Request('GET', Server::$url);
        $handler = $handlerFactory();
        $previous = new \Error('sink fatal');
        $writeCalled = false;
        $sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use (&$writeCalled, $previous): int {
                $writeCalled = true;

                throw $previous;
            },
        ]);

        try {
            $handler($request, ['sink' => $sink])->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        self::assertTrue($writeCalled);
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testSinkWriteFailureUsesFallbackMessageWhenThrowableMessageEmpty(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [], 'abc'),
        ]);
        $request = new Psr7\Request('GET', Server::$url);
        $handler = $handlerFactory();
        $sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data): int {
                throw new \RuntimeException('');
            },
        ]);

        try {
            $handler($request, ['sink' => $sink])->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame('Failed to write the response body', $e->getMessage());
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testSinkWriteReturningTooFewBytesRejectsAsResponseExceptionWithoutPrevious(callable $handlerFactory): void
    {
        $body = \str_repeat('x', 1024);
        $scenarios = [
            'return zero' => false,
            'short positive' => true,
        ];

        foreach ($scenarios as $label => $positiveShort) {
            Server::flush();
            Server::enqueue([
                new Psr7\Response(200, [], $body),
            ]);
            $request = new Psr7\Request('GET', Server::$url);
            $handler = $handlerFactory();
            $callbackRan = false;
            $sawPositiveShort = false;
            $sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
                'write' => static function (string $data) use ($positiveShort, &$callbackRan, &$sawPositiveShort): int {
                    $callbackRan = true;
                    $written = $positiveShort ? \strlen($data) - 1 : 0;
                    if ($written > 0 && $written < \strlen($data)) {
                        $sawPositiveShort = true;
                    }

                    return $written;
                },
            ]);

            try {
                $handler($request, ['sink' => $sink])->wait();

                self::fail("Expected ResponseException ({$label})");
            } catch (ResponseException $e) {
                self::assertSame(200, $e->getResponse()->getStatusCode(), $label);
                self::assertSame('Unable to write to stream', $e->getMessage(), $label);
                self::assertNull($e->getPrevious(), $label);
                self::assertNotInstanceOf(ResponseTransferException::class, $e, $label);
            } finally {
                Server::flush();

                if (\method_exists($handler, 'close')) {
                    $handler->close();
                }
            }

            self::assertTrue($callbackRan, "the short-write callback must run ({$label})");
            if ($positiveShort) {
                self::assertTrue($sawPositiveShort, "expected a genuinely positive short write ({$label})");
            }
        }
    }

    /**
     * @dataProvider curlHandlerProvider
     */
    public function testNonSeekableSinkSucceedsWithoutRewindThroughCurlHandlers(callable $handlerFactory): void
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['Content-Length' => '3'], 'abc'),
        ]);
        $request = new Psr7\Request('GET', Server::$url);
        $handler = $handlerFactory();
        $underlying = Psr7\Utils::streamFor();
        $stats = null;
        $statsCalled = 0;
        $rewindCalled = false;
        $seekCalled = false;
        $sink = Psr7\FnStream::decorate($underlying, [
            'isSeekable' => static function (): bool {
                return false;
            },
            'rewind' => static function () use (&$rewindCalled): void {
                $rewindCalled = true;

                throw new \RuntimeException('must not rewind a non-seekable sink');
            },
            'seek' => static function ($offset, $whence = \SEEK_SET) use (&$seekCalled): void {
                $seekCalled = true;

                throw new \RuntimeException('must not seek a non-seekable sink');
            },
        ]);

        try {
            $response = $handler($request, [
                'sink' => $sink,
                'on_stats' => static function (TransferStats $transferStats) use (&$stats, &$statsCalled): void {
                    ++$statsCalled;
                    $stats = $transferStats;
                },
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame($sink, $response->getBody());
        } finally {
            Server::flush();

            if (\method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        self::assertFalse($rewindCalled);
        self::assertFalse($seekCalled);
        $underlying->rewind();
        self::assertSame('abc', $underlying->getContents());
        self::assertSame(1, $statsCalled);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($response, $stats->getResponse());
        self::assertSame(0, $stats->getHandlerErrorData());
    }

    public function testSinkWritePsr7TimeoutRejectsAsRequestExceptionWithoutResponse(): void
    {
        $factory = new CurlFactory(3);
        $previous = new Psr7\Exception\TimeoutException('Unable to write to stream: timed out');
        $stats = null;
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);
        $easy->sinkWriteTimeoutException = $previous;
        $easy->errno = \CURLE_WRITE_ERROR;
        $handler = static function (RequestInterface $request, array $options) {
            self::fail('Did not expect timeout failure to be retried');

            return P\Create::promiseFor(new Psr7\Response());
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while writing the response body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertFalse($stats->hasResponse());
        self::assertNull($stats->getResponse());
        self::assertSame($request, $stats->getRequest());
        self::assertSame(\CURLE_WRITE_ERROR, $stats->getHandlerErrorData());
    }

    public function testGenericSinkWriteFailureWithoutResponseRejectsAsRequestExceptionWithoutRetry(): void
    {
        $factory = new CurlFactory(3);
        $request = new Psr7\Request('GET', Server::$url);
        $easy = $factory->create($request, []);
        $previous = new \RuntimeException('sink failed');
        $easy->sinkWriteException = $previous;
        $retried = false;
        $handler = static function () use (&$retried): P\PromiseInterface {
            $retried = true;

            return P\Create::promiseFor(new Psr7\Response(200));
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('sink failed', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkException::class, $e);
        }

        self::assertFalse($retried, 'A sink write failure must never trigger a request-body rewind retry');
    }

    public function testGenericSinkWriteFailureWithResponseRejectsWithoutRetryWhenErrnoIsZero(): void
    {
        $factory = new CurlFactory(3);
        $request = new Psr7\Request('GET', Server::$url);
        $response = new Psr7\Response(200, [], 'abc');
        $easy = $factory->create($request, []);
        $previous = new \RuntimeException('sink failed');
        $easy->response = $response;
        $easy->sinkWriteException = $previous;
        $retried = false;
        $handler = static function () use (&$retried): P\PromiseInterface {
            $retried = true;

            return P\Create::promiseFor(new Psr7\Response(200));
        };

        try {
            CurlFactory::finish($handler, $easy, $factory)->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
            self::assertSame('sink failed', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
        }

        self::assertFalse($retried, 'A sink write failure must never trigger a request-body rewind retry');
    }

    public function testInvokesOnStatsOnSuccess(): void
    {
        Server::flush();
        Server::enqueue([new Psr7\Response(200)]);
        $req = new Psr7\Request('GET', Server::$url);
        $gotStats = null;
        $handler = new Handler\CurlHandler();
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
        self::assertArrayHasKey('appconnect_time', $gotStats->getHandlerStats());
    }

    public function testInvokesOnStatsOnError(): void
    {
        $req = new Psr7\Request('GET', 'http://127.0.0.1:123');
        $gotStats = null;
        $handler = new Handler\CurlHandler();
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
        self::assertIsInt($gotStats->getHandlerErrorData());
        self::assertArrayHasKey('appconnect_time', $gotStats->getHandlerStats());
    }

    public function testInvokesOnStatsAfterSuccessHandleRelease(): void
    {
        $factory = new CurlFactory(1);
        $easy = null;
        $called = false;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_stats' => static function (TransferStats $stats) use (&$easy, $factory, &$called): void {
                $called = true;
                self::assertInstanceOf(EasyHandle::class, $easy);
                self::assertTrue($stats->hasResponse());
                self::assertArrayNotHasKey('handle', \get_object_vars($easy));
                self::assertCount(1, self::readIdleHandles($factory));
            },
        ]);
        $easy->response = new Psr7\Response(200);

        $promise = CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        );

        self::assertTrue($called);
        self::assertSame(200, $promise->wait()->getStatusCode());
    }

    public function testSurfacesSeekableBodyRewindFailureAsResponseException(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $previous = new \Exception('rewind failed');
        $response = new Psr7\Response(
            200,
            [],
            Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
                'isSeekable' => static function (): bool {
                    return true;
                },
                'rewind' => static function () use ($previous): void {
                    throw $previous;
                },
            ])
        );
        $easy = null;
        $exception = null;
        $stats = null;
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $transferStats) use (&$easy, $factory, &$stats): void {
                $stats = $transferStats;
                self::assertInstanceOf(EasyHandle::class, $easy);
                self::assertArrayNotHasKey('handle', \get_object_vars($easy));
                self::assertCount(1, self::readIdleHandles($factory));
            },
        ]);
        $easy->response = $response;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            )->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            $exception = $e;
            self::assertSame($request, $e->getRequest());
            self::assertSame($response, $e->getResponse());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(ResponseException::class, $exception);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($response, $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testSeekableBodyRewindErrorPropagates(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $previous = new \Error('rewind failed');
        $response = new Psr7\Response(
            200,
            [],
            Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
                'isSeekable' => static function (): bool {
                    return true;
                },
                'rewind' => static function () use ($previous): void {
                    throw $previous;
                },
            ])
        );
        $easy = $factory->create($request, []);
        $easy->response = $response;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            )->wait();
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testOnStatsExceptionEscapesOnSeekableBodyRewindFailureAfterHandleRelease(): void
    {
        $factory = new CurlFactory(1);
        $request = new Psr7\Request('GET', Server::$url);
        $rewindFailure = new \RuntimeException('rewind failed');
        $statsFailure = new \RuntimeException('stats failed');
        $response = new Psr7\Response(
            200,
            [],
            Psr7\FnStream::decorate(Psr7\Utils::streamFor('abc'), [
                'isSeekable' => static function (): bool {
                    return true;
                },
                'rewind' => static function () use ($rewindFailure): void {
                    throw $rewindFailure;
                },
            ])
        );
        $easy = null;
        $called = false;
        $easy = $factory->create($request, [
            'on_stats' => static function (TransferStats $stats) use (&$easy, $factory, &$called, $response, $rewindFailure, $statsFailure): void {
                $called = true;
                self::assertInstanceOf(EasyHandle::class, $easy);
                self::assertTrue($stats->hasResponse());
                self::assertSame($response, $stats->getResponse());
                self::assertArrayNotHasKey('handle', \get_object_vars($easy));
                self::assertCount(1, self::readIdleHandles($factory));

                $error = $stats->getHandlerErrorData();
                self::assertInstanceOf(ResponseException::class, $error);
                self::assertSame($rewindFailure, $error->getPrevious());
                self::assertNotInstanceOf(ResponseTransferException::class, $error);
                self::assertNotInstanceOf(ResponseTimeoutException::class, $error);
                self::assertNotInstanceOf(NetworkExceptionInterface::class, $error);

                throw $statsFailure;
            },
        ]);
        $easy->response = $response;

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            );

            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame($statsFailure, $e);
        }

        self::assertTrue($called);
    }

    public function testInvokesOnStatsAfterErrorHandleRelease(): void
    {
        $factory = new CurlFactory(1);
        $easy = null;
        $called = false;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_stats' => static function (TransferStats $stats) use (&$easy, $factory, &$called): void {
                $called = true;
                self::assertInstanceOf(EasyHandle::class, $easy);
                self::assertFalse($stats->hasResponse());
                self::assertSame(\CURLE_COULDNT_CONNECT, $stats->getHandlerErrorData());
                self::assertArrayNotHasKey('handle', \get_object_vars($easy));
                self::assertCount(1, self::readIdleHandles($factory));
            },
        ]);
        $easy->errno = \CURLE_COULDNT_CONNECT;

        CurlFactory::finish(
            static function (): void {
            },
            $easy,
            $factory
        )->wait(false);

        self::assertTrue($called);
    }

    public function testOnStatsExceptionEscapesAfterHandleRelease(): void
    {
        $factory = new CurlFactory(1);
        $previous = new \RuntimeException('stats failed');
        $easy = null;
        $easy = $factory->create(new Psr7\Request('GET', Server::$url), [
            'on_stats' => static function () use (&$easy, $factory, $previous): void {
                self::assertInstanceOf(EasyHandle::class, $easy);
                self::assertArrayNotHasKey('handle', \get_object_vars($easy));
                self::assertCount(1, self::readIdleHandles($factory));

                throw $previous;
            },
        ]);
        $easy->response = new Psr7\Response(200);

        try {
            CurlFactory::finish(
                static function (): void {
                },
                $easy,
                $factory
            );
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testRewindsBodyIfPossible(): void
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

    public function testDoesNotRewindUnseekableBody(): void
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

    public function testRelease(): void
    {
        $factory = new CurlFactory(1);
        $easyHandle = new EasyHandle();
        $easyHandle->handle = \curl_init();

        self::assertEmpty($factory->release($easyHandle));
    }

    /**
     * https://github.com/guzzle/guzzle/issues/2735
     */
    public function testBodyEofOnWindows(): void
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

    public function testHandlesGarbageHttpServerGracefully(): void
    {
        $a = new Handler\CurlMultiHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('cURL error 1: Received HTTP/0.9 when not allowed');

        $a(new Psr7\Request('GET', Server::$url.'guzzle-server/garbage'), [])->wait();
    }

    public function testHandlesInvalidStatusCodeGracefully(): void
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
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    private static function assertAuthenticatedProxyConnectionReuseOptions(): void
    {
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    /**
     * @param string[] $expectedProtocols
     */
    private static function assertCurlProtocols(array $expectedProtocols): void
    {
        if (CurlVersion::supportsProtocolsStr()) {
            self::assertSame(
                \implode(',', $expectedProtocols),
                $_SERVER['_curl'][(int) \constant('CURLOPT_PROTOCOLS_STR')]
            );
            self::assertArrayNotHasKey(\CURLOPT_PROTOCOLS, $_SERVER['_curl']);

            return;
        }

        self::assertSame(self::curlProtocolMask($expectedProtocols), $_SERVER['_curl'][\CURLOPT_PROTOCOLS]);
    }

    /**
     * @param string[] $protocols
     */
    private static function curlProtocolMask(array $protocols): int
    {
        $mask = 0;

        if (\in_array('http', $protocols, true)) {
            $mask |= \CURLPROTO_HTTP;
        }

        if (\in_array('https', $protocols, true)) {
            $mask |= \CURLPROTO_HTTPS;
        }

        return $mask;
    }

    /**
     * @param array<int|string, mixed> $options
     */
    private static function createWithCurlVersion(string $version, string $uri, array $options): void
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $version,
            'features' => self::curlSslFeature(),
        ]);

        try {
            $f = new CurlFactory(3);
            $f->create(new Psr7\Request('GET', $uri), $options);
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

    /**
     * @param array<int|string, mixed> $options
     *
     * @return array<int|string, mixed>
     */
    private static function getDefaultCurlConf(RequestInterface $request, array $options): array
    {
        $factory = new CurlFactory(3);
        $easy = new EasyHandle();
        $easy->request = $request;
        $easy->options = $options;

        $method = new \ReflectionMethod(CurlFactory::class, 'getDefaultConf');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($factory, $easy);
    }

    private static function requireHttp3TestConstants(): void
    {
        foreach (['CURL_VERSION_HTTP3', 'CURL_HTTP_VERSION_3'] as $constant) {
            if (!\defined($constant)) {
                self::markTestSkipped($constant.' is not available.');
            }
        }
    }

    private static function http3FeatureMask(bool $withHttp2): int
    {
        self::requireHttp3TestConstants();

        $features = (int) \constant('CURL_VERSION_HTTP3') | self::curlSslFeature();
        if ($withHttp2) {
            if (!\defined('CURL_VERSION_HTTP2')) {
                self::markTestSkipped('CURL_VERSION_HTTP2 is not available.');
            }

            $features |= (int) \constant('CURL_VERSION_HTTP2');
        }

        return $features;
    }

    private static function curlSslFeature(): int
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is not available.');
        }

        return \CURL_VERSION_SSL;
    }

    public static function curlHandlerProvider(): array
    {
        return [
            'curl' => [static function (): callable {
                return new Handler\CurlHandler();
            }],
            'curl_multi' => [static function (): callable {
                return new Handler\CurlMultiHandler();
            }],
        ];
    }

    private static function progressCallbackOption(): int
    {
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            return (int) \constant('CURLOPT_XFERINFOFUNCTION');
        }

        return \CURLOPT_PROGRESSFUNCTION;
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

    private static function readIdleHandles(CurlFactory $factory): array
    {
        $readHandles = \Closure::bind(static function (CurlFactory $factory): array {
            return $factory->handles;
        }, null, CurlFactory::class);

        return $readHandles($factory);
    }

    private static function readShareHandle(CurlFactory $factory)
    {
        $readShareHandle = \Closure::bind(static function (CurlFactory $factory) {
            return $factory->shareHandle;
        }, null, CurlFactory::class);

        return $readShareHandle($factory);
    }

    private static function requestWithProtocolVersion(string $protocolVersion): RequestInterface
    {
        return new class($protocolVersion) extends Psr7\Request {
            /** @var string */
            private $protocolVersion;

            public function __construct(string $protocolVersion)
            {
                parent::__construct('GET', Server::$url);

                $this->protocolVersion = $protocolVersion;
            }

            public function getProtocolVersion(): string
            {
                return $this->protocolVersion;
            }

            public function withProtocolVersion(string $version): MessageInterface
            {
                if ($this->protocolVersion === $version) {
                    return $this;
                }

                $new = clone $this;
                $new->protocolVersion = $version;

                return $new;
            }
        };
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (
            !\function_exists('curl_share_init')
            || !\function_exists('curl_share_setopt')
            || !\defined('CURLOPT_SHARE')
        ) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }

    /**
     * @param resource|\CurlShareHandle $shareHandle
     */
    private static function closeShareHandleOnPhp7($shareHandle): void
    {
        if (PHP_VERSION_ID < 80000 && \is_resource($shareHandle)) {
            \curl_share_close($shareHandle);
        }
    }
}
