<?php

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\TransportSharing;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\TransportSharing
 * @covers \GuzzleHttp\Handler\CurlShareHandleState
 */
class CurlShareHandleStateTest extends TestCase
{
    public function setUp(): void
    {
        $_SERVER['curl_test'] = true;
        unset(
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_close_count'],
            $_SERVER['curl_share_setopt_fail']
        );
    }

    public function tearDown(): void
    {
        unset(
            $_SERVER['curl_test'],
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_close_count'],
            $_SERVER['curl_share_setopt_fail']
        );
    }

    public function testNullDisablesSharing(): void
    {
        self::assertNull(CurlShareHandleState::fromOption(null));
    }

    public function testNoneDisablesSharing(): void
    {
        self::assertNull(CurlShareHandleState::fromOption(TransportSharing::NONE));
    }

    public function testHandlerPreferCreatesShareHandleForDnsAndSslSession(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $state = CurlShareHandleState::fromOption(TransportSharing::HANDLER_PREFER);

        self::assertInstanceOf(CurlShareHandleState::class, $state);
        self::assertSame(TransportSharing::HANDLER_PREFER, $state->mode);
        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);

        if (\defined('CURL_LOCK_DATA_CONNECT')) {
            self::assertNotContains(\constant('CURL_LOCK_DATA_CONNECT'), $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        }
    }

    public function testHandlerRequireCreatesShareHandleForDnsAndSslSession(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $state = CurlShareHandleState::fromOption(TransportSharing::HANDLER_REQUIRE);

        self::assertInstanceOf(CurlShareHandleState::class, $state);
        self::assertSame(TransportSharing::HANDLER_REQUIRE, $state->mode);
        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }

    /**
     * @dataProvider invalidShareOptions
     *
     * @param mixed $share
     */
    public function testRejectsInvalidShareOptions($share): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CurlShareHandleState::fromOption($share);
    }

    public static function invalidShareOptions(): iterable
    {
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[]];
        yield 'string' => ['dns'];
    }

    public function testAllowsHandlerPreferWithCustomFactory(): void
    {
        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ], 'CurlHandler');

        self::assertTrue(true);
    }

    public function testRejectsHandlerRequireWithCustomFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
        ], 'CurlHandler');
    }

    public function testAllowsDisabledTransportSharingWithCustomFactory(): void
    {
        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::NONE,
        ], 'CurlHandler');

        self::assertTrue(true);
    }

    public function testHandlerPreferFallsBackToNoSharingWhenShareSetupFails(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $_SERVER['curl_share_setopt_fail'] = \CURL_LOCK_DATA_DNS;

        self::assertNull(CurlShareHandleState::fromOption(TransportSharing::HANDLER_PREFER));
    }

    public function testHandlerRequireShareSetoptFailureThrows(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $_SERVER['curl_share_setopt_fail'] = \CURL_LOCK_DATA_DNS;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to configure cURL share handle');

        CurlShareHandleState::fromOption(TransportSharing::HANDLER_REQUIRE);
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt')) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }
}
