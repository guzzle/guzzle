<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\CurlHandler
 */
class CurlHandlerTest extends TestCase
{
    protected function getHandler($options = [])
    {
        return new CurlHandler($options);
    }

    public function testCreatesCurlErrors()
    {
        $handler = new CurlHandler();
        $request = new Request('GET', 'http://localhost:123');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL');
        $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001])->wait();
    }

    public function testRejectsNonCallableOnTrailersBeforeTransfer()
    {
        $handler = new CurlHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('on_trailers must be callable');

        $handler(new Request('GET', Server::$url), ['on_trailers' => 'not-a-function']);
    }

    public function testRedactsUserInfoInErrors()
    {
        $handler = new CurlHandler();
        $request = new Request('GET', 'http://my_user:secretPass@localhost:123');

        try {
            $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001])->wait();
            $this->fail('Must throw an Exception.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('secretPass', $e->getMessage());
        }
    }

    public function testReusesHandles()
    {
        Server::flush();
        $response = new Response(200);
        Server::enqueue([$response, $response]);
        $a = new CurlHandler();
        $request = new Request('GET', Server::$url);
        self::assertInstanceOf(FulfilledPromise::class, $a($request, []));
        self::assertInstanceOf(FulfilledPromise::class, $a($request, []));
    }

    public function testDoesSleep()
    {
        $response = new Response(200);
        Server::enqueue([$response]);
        $a = new CurlHandler();
        $request = new Request('GET', Server::$url);
        $s = Utils::currentTime();
        $a($request, ['delay' => 0.1])->wait();
        self::assertGreaterThan(0.0001, Utils::currentTime() - $s);
    }

    public function testTransportSharingOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        Server::flush();
        Server::enqueue([new Response(200)]);

        try {
            $handler = new CurlHandler([
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
            unset($_SERVER['curl_test'], $_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testPreferredTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        self::assertInstanceOf(CurlHandler::class, $handler);
    }

    public function testRequiredTransportSharingCannotBeUsedWithCustomFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
        ]);
    }

    public function testDisabledTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::NONE,
        ]);

        self::assertInstanceOf(CurlHandler::class, $handler);
    }

    public function testDeprecatesUnknownConstructorOption(): void
    {
        $deprecation = self::captureDeprecation(static function (): void {
            new CurlHandler(['unknown' => true]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the unknown constructor option.');
        self::assertStringContainsString('The "unknown" CurlHandler constructor option is unknown', $deprecation);
    }

    public function testAllowsRawPipewaitWithExplicitMultiplex()
    {
        if (!\defined('CURLOPT_PIPEWAIT') || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or multiplex support is unavailable.');
        }

        // A direct handler has no multi handle whose waiting behavior the
        // raw option could reverse, so only the CurlMultiHandler rejects the
        // raw CURLOPT_PIPEWAIT conflict.
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl']);

        try {
            Server::flush();
            Server::enqueue([new Response()]);
            $handler = new CurlHandler();
            $response = $handler(new Request('GET', Server::$url, [], null, '1.1'), [
                'multiplex' => Multiplexing::WAIT,
                'curl' => [(int) \constant('CURLOPT_PIPEWAIT') => true],
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($_SERVER['_curl'][(int) \constant('CURLOPT_PIPEWAIT')]);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl']);
        }
    }

    public function testUsesContentLengthWhenOverInMemorySize()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $stream = Psr7\Utils::streamFor(\str_repeat('.', 1000000));
        $handler = new CurlHandler();
        $request = new Request(
            'PUT',
            Server::$url,
            ['Content-Length' => '1000000'],
            $stream
        );
        $handler($request, [])->wait();
        $received = Server::received()[0];
        self::assertEquals(1000000, $received->getHeaderLine('Content-Length'));
        self::assertFalse($received->hasHeader('Transfer-Encoding'));
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt') || !\defined('CURLOPT_SHARE')) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
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
