<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
        $checks = [\CURLOPT_READFUNCTION, \CURLOPT_FILE, \CURLOPT_INFILE];
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
                // The redacted form follows the installed psr7 version:
                // 'user:***' on psr7 2, '***' on psr7 3.
                $redactedUserInfo = Psr7\Utils::redactUserInfo(
                    new Psr7\Uri('foo://user:secret@127.0.0.1:1')
                )->getUserInfo();

                self::assertStringContainsString('foo://'.$redactedUserInfo.'@127.0.0.1:1', $e->getMessage());
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
        $redactedUserInfo = Psr7\Utils::redactUserInfo(new Psr7\Uri($proxy))->getUserInfo();

        $redacted = self::redactProxyUserInfo('Failed to connect via '.$proxy, $proxy);

        self::assertStringNotContainsString('secret', $redacted);
        self::assertSame('Failed to connect via http://'.$redactedUserInfo.'@proxy.example.com:8125', $redacted);
    }

    public function testRedactsUnparsableProxyCredentialsIndependentlyOfCurlErrorText(): void
    {
        $proxy = 'http://user:secret@127.0.0.1:99999999';

        $redacted = self::redactProxyUserInfo("Unsupported proxy syntax in '".$proxy."'", $proxy);

        self::assertStringNotContainsString('secret', $redacted);
        self::assertSame("Unsupported proxy syntax in 'http://***@127.0.0.1:99999999'", $redacted);
    }

    public function testLeavesCurlErrorsUntouchedForProxiesWithoutCredentials(): void
    {
        $error = 'Failed to connect to proxy.example.com:8125';

        self::assertSame($error, self::redactProxyUserInfo($error, 'http://proxy.example.com:8125'));
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

    private static function redactProxyUserInfo(string $error, ?string $proxy): string
    {
        $method = new \ReflectionMethod(CurlFactory::class, 'redactProxyUserInfo');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke(null, $error, $proxy);
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
