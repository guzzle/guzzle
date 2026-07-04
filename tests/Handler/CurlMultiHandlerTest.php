<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class CurlMultiHandlerTest extends TestCase
{
    public function setUp(): void
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_multi_setopt_fail']);
    }

    public function tearDown(): void
    {
        unset($_SERVER['_curl'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_multi_setopt_fail'], $_SERVER['curl_test']);
    }

    public function testCanAddCustomCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $request = new Request('GET', Server::$url);
        $a($request, []);
        self::assertEquals(5, $_SERVER['_curl_multi'][\CURLMOPT_MAXCONNECTS]);
    }

    public function testTimeToNextDoesNotTruncateSubSecondDelay(): void
    {
        $handler = new CurlMultiHandler();

        $delays = new \ReflectionProperty(CurlMultiHandler::class, 'delays');
        if (\PHP_VERSION_ID < 80100) {
            $delays->setAccessible(true);
        }
        $delays->setValue($handler, [1 => Utils::currentTime() + 0.5]);

        $timeToNext = new \ReflectionMethod(CurlMultiHandler::class, 'timeToNext');
        if (\PHP_VERSION_ID < 80100) {
            $timeToNext->setAccessible(true);
        }

        self::assertGreaterThan(100000, $timeToNext->invoke($handler));
    }

    public function testCanAddConnectionCapOptions(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([
            'max_host_connections' => 2,
            'max_total_connections' => 5,
        ]);

        self::readMultiProperty($handler, '_mh');

        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
        self::assertSame(5, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_TOTAL_CONNECTIONS')]);
    }

    public function testSynchronousRequestsDoNotWaitForOtherTransfers(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);

        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
        $immediate = $handler(new Request('GET', Server::$url), [RequestOptions::SYNCHRONOUS => true]);

        $response = $immediate->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(P\Is::pending($delayed));

        $delayed->cancel();
    }

    public function testSynchronousWaitDoesNotFollowReusedHandleFromCompletionCallback(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);
        $spawned = null;

        $response = $handler(new Request('GET', Server::$url), [
            RequestOptions::SYNCHRONOUS => true,
            'on_trailers' => static function () use ($handler, &$spawned): void {
                $spawned = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(P\PromiseInterface::class, $spawned);
        self::assertTrue(P\Is::pending($spawned));

        $spawned->cancel();
    }

    /**
     * @dataProvider invalidConnectionCapOptionProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidConnectionCapOptions(string $option, $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' must be a positive integer.');

        new CurlMultiHandler([$option => $value]);
    }

    public function testRejectsConnectionCapOptionsWhenLibcurlDoesNotSupportThem(): void
    {
        if (!\defined('CURLMOPT_MAX_HOST_CONNECTIONS') || !\defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            self::markTestSkipped('cURL multi connection cap options are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.29.0', 'features' => 0]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('requires PHP cURL support for CURLMOPT_MAX_HOST_CONNECTIONS');

            new CurlMultiHandler(['max_host_connections' => 1]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testRejectsNamedAndRawConnectionCapOptions(string $option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' conflicts with a '.$constant.' entry in the "options" array.');

        new CurlMultiHandler([
            $option => 1,
            'options' => [\constant($constant) => 2],
        ]);
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testDeprecatesRawConnectionCapCurlMultiOptions(string $_option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $deprecation = self::captureDeprecation(static function () use ($constant): void {
            new CurlMultiHandler(['options' => [\constant($constant) => 2]]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the raw cURL multi connection cap option.');
        self::assertStringContainsString('Passing '.$constant, $deprecation);
        self::assertStringContainsString('Use the "'.$_option.'" client option or cURL multi handler option instead.', $deprecation);
    }

    public function testWarnsWhenCurlMultiOptionCannotBeApplied()
    {
        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_MAXCONNECTS;

        $warning = null;
        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if ($severity !== \E_USER_WARNING) {
                return false;
            }

            $warning = $message;

            return true;
        }, \E_USER_WARNING);

        try {
            self::readMultiProperty($handler, '_mh');
        } finally {
            \restore_error_handler();
        }

        self::assertNotNull($warning, 'Expected a warning for the rejected cURL multi option.');
        self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAXCONNECTS', $warning);
        self::assertStringContainsString('ignored by the runtime libcurl', $warning);
    }

    public function testDeprecatesUnknownConstructorOption()
    {
        $deprecation = self::captureDeprecation(static function (): void {
            new CurlMultiHandler(['unknown' => true]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the unknown constructor option.');
        self::assertStringContainsString('The "unknown" CurlMultiHandler constructor option is unknown', $deprecation);
    }

    public function testRejectsExplicitMultiplexWhenPipeliningIsDisabled()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public function testRejectsExplicitMultiplexWhenPipeliningIsHttp1Only()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // CURLPIPE_HTTP1 has been a no-op since libcurl 7.62.0 but still lacks
        // the CURLPIPE_MULTIPLEX bit, so it silently disables multiplexing.
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_HTTP1,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public function testRejectsRequireWaitWhenPipeliningIsDisabled()
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            $a = new CurlMultiHandler(['options' => [
                \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
            ]]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('set the "multiplex" option to "eager"');
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), ['multiplex' => Multiplexing::REQUIRE_WAIT]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testRejectsRequireEagerWhenPipeliningIsDisabled()
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            // REQUIRE_EAGER never sets CURLOPT_PIPEWAIT, so this pins the
            // marker-independent required-family arm of the guard.
            $a = new CurlMultiHandler(['options' => [
                \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
            ]]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('set the "multiplex" option to "eager"');
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), ['multiplex' => Multiplexing::REQUIRE_EAGER]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testDefaultMultiplexDoesNotThrowWhenPipeliningIsDisabled()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // The default (key absent) leaves multiplexing to libcurl: no PIPEWAIT
        // is written and the guard never fires - an explicit
        // wait/require-family option is required for the conflict.
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '2.0'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey((int) \constant('CURLOPT_PIPEWAIT'), $_SERVER['_curl']);
    }

    public function testAllowsExplicitMultiplexWhenPipeliningIncludesMultiplexBit()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_MULTIPLEX,
        ]]);
        $promise = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
        $promise->cancel();
        self::assertInstanceOf(P\PromiseInterface::class, $promise);
    }

    public function testAllowsDisabledPipeliningWhenMultiplexIsEager()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url), ['multiplex' => Multiplexing::EAGER])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsExplicitWaitForHttp11WhenPipeliningIsDisabled()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::WAIT])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsDisabledPipeliningWhenMultiplexIsAbsent()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url), [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSendsRequest()
    {
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = $a($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreatesExceptions()
    {
        $a = new CurlMultiHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL error');
        $a(new Request('GET', 'http://localhost:123'), [])->wait();
    }

    public function testCanSetSelectTimeout()
    {
        $a = new CurlMultiHandler(['select_timeout' => 2]);
        self::assertEquals(2, self::readSelectTimeout($a));
    }

    public function testDeprecatesInvalidSelectTimeout()
    {
        $deprecation = self::captureDeprecation(static function (): void {
            new CurlMultiHandler(['select_timeout' => []]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the invalid select_timeout option.');
        self::assertStringContainsString('Passing a non-numeric "select_timeout" CurlMultiHandler option is deprecated', $deprecation);
    }

    public static function connectionCapOptionProvider(): iterable
    {
        yield 'max host connections' => ['max_host_connections', 'CURLMOPT_MAX_HOST_CONNECTIONS'];
        yield 'max total connections' => ['max_total_connections', 'CURLMOPT_MAX_TOTAL_CONNECTIONS'];
    }

    public static function invalidConnectionCapOptionProvider(): iterable
    {
        foreach (['max_host_connections', 'max_total_connections'] as $option) {
            yield $option.' zero' => [$option, 0];
            yield $option.' negative' => [$option, -1];
            yield $option.' float' => [$option, 1.0];
            yield $option.' string' => [$option, '1'];
        }
    }

    public function testTransportSharingOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

        try {
            Server::flush();
            Server::enqueue([new Response(200)]);

            $handler = new CurlMultiHandler([
                'transport_sharing' => TransportSharing::HANDLER_PREFER,
            ]);

            $handler(new Request('GET', Server::$url), [])->wait();

            self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testPreferredTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
    }

    public function testRequiredTransportSharingCannotBeUsedWithCustomFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
        ]);
    }

    public function testDisabledTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::NONE,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
    }

    public function testDestructorDoesNotThrowWhenCurlMultiCloseFails()
    {
        $handler = new CurlMultiHandler();

        $setMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): void {
            $handler->_mh = new \stdClass();
        }, null, CurlMultiHandler::class);
        $hasMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): bool {
            return isset($handler->_mh);
        }, null, CurlMultiHandler::class);

        $setMultiHandle($handler);
        \set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $handler->__destruct();
        } finally {
            \restore_error_handler();
        }

        self::assertFalse($hasMultiHandle($handler));
    }

    public function testCanCancel()
    {
        Server::flush();
        $response = new Response(200);
        Server::enqueue(\array_fill_keys(\range(0, 10), $response));
        $a = new CurlMultiHandler();
        $responses = [];
        for ($i = 0; $i < 10; ++$i) {
            $response = $a(new Request('GET', Server::$url), []);
            $response->cancel();
            $responses[] = $response;
        }

        foreach ($responses as $r) {
            self::assertTrue(P\Is::rejected($r));
        }
    }

    public function testCanCancelFromProgressCallback()
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $promise = null;
        $progressCalls = 0;
        $cancelled = false;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function (
                $downloadSize,
                $downloaded,
                $uploadSize,
                $uploaded
            ) use (&$promise, &$progressCalls, &$cancelled): void {
                ++$progressCalls;

                if (!$cancelled) {
                    $cancelled = true;
                    $promise->cancel();
                }
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($promise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for cURL progress cancellation.');
                }

                $handler->tick();
            }

            self::assertGreaterThan(0, $progressCalls);
            self::assertTrue($cancelled);
            self::assertTrue(P\Is::rejected($promise));
        } finally {
            if (\method_exists($handler, 'close')) {
                $handler->close();
            }

            Server::flush();
        }
    }

    public function testCannotCancelFinished()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $a = new CurlMultiHandler();
        $response = $a(new Request('GET', Server::$url), []);
        $response->wait();
        $response->cancel();
        self::assertTrue(P\Is::fulfilled($response));
    }

    public function testDelaysConcurrently()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $expected = Utils::currentTime() + (100 / 1000);
        $response = $a(new Request('GET', Server::$url), ['delay' => 100]);
        $response->wait();
        self::assertGreaterThanOrEqual($expected, Utils::currentTime());
    }

    public function testManualTickRejectsPromiseWhenFinishThrows()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');
        $promise = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous) {
                throw $previous;
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;
            while (P\Is::pending($promise) && \microtime(true) < $deadline) {
                $handler->tick();
            }

            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            Server::flush();
        }
    }

    public function testFinishThrowDoesNotAffectSiblingTransfers()
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');

        $bad = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous) {
                throw $previous;
            },
        ]);
        $good = $handler(new Request('GET', Server::$url), []);

        try {
            $deadline = \microtime(true) + 5;
            while ((P\Is::pending($bad) || P\Is::pending($good)) && \microtime(true) < $deadline) {
                $handler->tick();
            }

            self::assertTrue(P\Is::fulfilled($good));
            self::assertSame(200, $good->wait()->getStatusCode());

            self::assertTrue(P\Is::rejected($bad));
            try {
                $bad->wait();
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            Server::flush();
        }
    }

    public function testUsesTimeoutEnvironmentVariables()
    {
        unset($_SERVER['GUZZLE_CURL_SELECT_TIMEOUT']);
        \putenv('GUZZLE_CURL_SELECT_TIMEOUT=');

        try {
            $a = new CurlMultiHandler();
            // Default if no options are given and no environment variable is set
            self::assertEquals(1, self::readSelectTimeout($a));

            \putenv('GUZZLE_CURL_SELECT_TIMEOUT=3');
            $a = new CurlMultiHandler();
            // Handler reads from the environment if no options are given
            self::assertEquals(3, self::readSelectTimeout($a));
        } finally {
            \putenv('GUZZLE_CURL_SELECT_TIMEOUT=');
        }
    }

    public function throwsWhenAccessingInvalidProperty()
    {
        $h = new CurlMultiHandler();

        $this->expectException(\BadMethodCallException::class);
        $h->foo;
    }

    public function testFirstProxyTunnelOwnerLatchesWithoutRecreatingMultiHandle(): void
    {
        $handler = new CurlMultiHandler();

        // Initialize the multi handle so we can detect an unwanted recreation.
        $mh = self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-a'));

        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'The first owner must not recreate the multi handle.');
    }

    public function testIdleProxyTunnelOwnerChangeRecreatesMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));

        self::assertSame('sig-b', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertNotSame($mh, self::readMultiProperty($handler, '_mh'), 'An idle owner change must recreate the multi handle.');
    }

    public function testBusyProxyTunnelOwnerChangeIsolatesTheTransfer(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');
        // A busy multi: another transfer is tracked.
        self::setMultiProperty($handler, 'handles', [0 => ['busy']]);

        $easy = self::easyWithSignature('sig-b');
        self::applyProxyTunnelOwnership($handler, $easy);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'), 'A busy owner change must not move the owner.');
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'A busy owner change must not recreate the multi handle.');
    }

    public function testProcessingMessagesGuardPreventsMultiRecreation(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');
        // The multi is idle by every other measure, but a retried transfer is
        // re-invoking the handler from inside processMessages.
        self::setMultiProperty($handler, 'processingMessages', true);

        $easy = self::easyWithSignature('sig-b');
        self::applyProxyTunnelOwnership($handler, $easy);

        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'Recreating the multi handle mid-iteration would corrupt the read loop.');
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testNullSignatureNeverDisturbsProxyTunnelOwnership(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature(null));

        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'));
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);
    }

    public function testActiveForeignProxyTunnelForcesOwnerTransferIsolation(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-a' => 1, 'sig-b' => 1]);

        $easy = self::easyWithSignature('sig-a');
        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $isolate($handler, $easy);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'), 'Isolation must not move the scalar owner.');
    }

    public function testOwnerMatchingTransferIsNotIsolatedWhenNoForeignSignatureIsActive(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-a' => 1]);
        unset($_SERVER['_curl']);

        $easy = self::easyWithSignature('sig-a');
        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $isolate($handler, $easy);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);
    }

    public function testForeignTransferIsIsolatedWhenOwnerIsActive(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-a' => 1]);

        $easy = self::easyWithSignature('sig-b');
        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $isolate($handler, $easy);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testActiveProxyTunnelSignatureCountsAreReferenceCounted(): void
    {
        $handler = new CurlMultiHandler();
        $first = self::easyWithSignature('sig-b');
        $second = self::easyWithSignature('sig-b');
        $idFirst = (int) $first->handle;
        $idSecond = (int) $second->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);
        $unmarkById = \Closure::bind(static function (CurlMultiHandler $handler, int $id): void {
            $handler->unmarkProxyTunnelActiveById($id);
        }, null, CurlMultiHandler::class);

        $mark($handler, $first);
        $mark($handler, $second);
        self::assertSame(['sig-b' => 2], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $idFirst);
        self::assertSame(['sig-b' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $idSecond);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testDelayedTransferIsNotActiveUntilAddedToMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $easy->options = ['delay' => 10000];

        $addRequest = \Closure::bind(static function (CurlMultiHandler $handler, array $entry): void {
            $handler->addRequest($entry);
        }, null, CurlMultiHandler::class);
        $addCurlHandle = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->addCurlHandle($easy);
        }, null, CurlMultiHandler::class);

        $addRequest($handler, ['easy' => $easy, 'deferred' => new P\Promise()]);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'A delayed transfer must not be counted before it attaches.');

        $addCurlHandle($handler, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'The transfer must be counted only once attached.');
    }

    public function testDeferredCancelCleanupDoesNotDoubleDecrementActiveSignature(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $id = (int) $easy->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);
        $unmarkById = \Closure::bind(static function (CurlMultiHandler $handler, int $id): void {
            $handler->unmarkProxyTunnelActiveById($id);
        }, null, CurlMultiHandler::class);
        $unmark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->unmarkProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);

        $mark($handler, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $id);
        $unmark($handler, $easy);

        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testCompletionUnmarksBeforeFinishCanReenter(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $id = (int) $easy->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);
        $removeCompleted = \Closure::bind(static function (CurlMultiHandler $handler, int $id, $handle): void {
            $handler->removeCompletedHandleFromMulti($id, $handle);
        }, null, CurlMultiHandler::class);

        $mark($handler, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([$id => 'sig-a'], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));

        $removeCompleted($handler, $id, $easy->handle);

        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testNoDelayAddRequestIsolatesAndMarksThroughTheWrapper(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-b' => 1]);
        self::setMultiProperty($handler, 'activeProxyTunnelHandles', [-1 => 'sig-b']);

        $addRequest = \Closure::bind(static function (CurlMultiHandler $handler, array $entry): void {
            $handler->addRequest($entry);
        }, null, CurlMultiHandler::class);

        $easy = self::easyWithSignature('sig-a');
        $easy->options = [];
        $addRequest($handler, ['easy' => $easy, 'deferred' => new P\Promise()]);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame(1, self::readMultiProperty($handler, 'activeProxyTunnelSignatures')['sig-a'] ?? 0, 'The no-delay transfer must be marked active.');

        unset($_SERVER['_curl']);
        $nullEasy = self::easyWithSignature(null);
        $nullEasy->options = [];
        $addRequest($handler, ['easy' => $nullEasy, 'deferred' => new P\Promise()]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);
        self::assertArrayNotHasKey((int) $nullEasy->handle, self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testNullSignatureNeverEntersActiveMaps(): void
    {
        $handler = new CurlMultiHandler();
        $nullEasy = self::easyWithSignature(null);

        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);

        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-b' => 1]);
        unset($_SERVER['_curl']);
        $isolate($handler, $nullEasy);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);

        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', []);
        $mark($handler, $nullEasy);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    private static function easyWithSignature(?string $signature): EasyHandle
    {
        $easy = new EasyHandle();
        $easy->request = new Request('GET', 'https://example.com');
        $easy->handle = \curl_init();
        $easy->proxyTunnelSignature = $signature;

        return $easy;
    }

    private static function applyProxyTunnelOwnership(CurlMultiHandler $handler, EasyHandle $easy): void
    {
        $invoke = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->applyProxyTunnelOwnership($easy);
        }, null, CurlMultiHandler::class);

        $invoke($handler, $easy);
    }

    /**
     * @param mixed $value
     */
    private static function setMultiProperty(CurlMultiHandler $handler, string $name, $value): void
    {
        $set = \Closure::bind(static function (CurlMultiHandler $handler) use ($name, $value): void {
            $handler->{$name} = $value;
        }, null, CurlMultiHandler::class);

        $set($handler);
    }

    /**
     * @return mixed
     */
    private static function readMultiProperty(CurlMultiHandler $handler, string $name)
    {
        $get = \Closure::bind(static function (CurlMultiHandler $handler) use ($name) {
            return $handler->{$name};
        }, null, CurlMultiHandler::class);

        return $get($handler);
    }

    private static function readSelectTimeout(CurlMultiHandler $handler)
    {
        $readSelectTimeout = \Closure::bind(static function (CurlMultiHandler $handler) {
            return $handler->selectTimeout;
        }, null, CurlMultiHandler::class);

        return $readSelectTimeout($handler);
    }

    private static function captureDeprecation(callable $callback): ?string
    {
        $deprecation = null;
        \set_error_handler(static function (int $severity, string $message) use (&$deprecation): bool {
            if ($severity !== \E_USER_DEPRECATED) {
                return false;
            }

            $deprecation = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $callback();
        } finally {
            \restore_error_handler();
        }

        return $deprecation;
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt') || !\defined('CURLOPT_SHARE')) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }

    private static function skipIfConnectionCapCurlMultiOptionsUnavailable(): void
    {
        if (!CurlVersion::supportsConnectionCaps()) {
            self::markTestSkipped('cURL multi connection cap options are unavailable.');
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
