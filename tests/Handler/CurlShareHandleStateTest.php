<?php

declare(strict_types=1);

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
            $_SERVER['_curl_share_init_persistent_count'],
            $_SERVER['_curl_share_persistent_options'],
            $_SERVER['curl_share_setopt_fail'],
            $_SERVER['curl_share_init_persistent_fail']
        );
    }

    public function tearDown(): void
    {
        unset(
            $_SERVER['curl_test'],
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_close_count'],
            $_SERVER['_curl_share_init_persistent_count'],
            $_SERVER['_curl_share_persistent_options'],
            $_SERVER['curl_share_setopt_fail'],
            $_SERVER['curl_share_init_persistent_fail']
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
        self::assertHandlerShareWasCreated();
    }

    public function testPersistentPreferFallsBackToHandlerSharingWhenPersistentSharingIsUnavailable(): void
    {
        self::skipIfCurlShareIsUnavailable();

        if (self::persistentCurlShareIsAvailable()) {
            $_SERVER['curl_share_init_persistent_fail'] = true;
        }

        $state = CurlShareHandleState::fromOption(TransportSharing::PERSISTENT_PREFER);

        self::assertInstanceOf(CurlShareHandleState::class, $state);
        self::assertSame(TransportSharing::HANDLER_PREFER, $state->mode);
        self::assertHandlerShareWasCreated();
    }

    public function testPersistentPreferFallsBackToNoSharingWhenHandlerSharingFallbackFails(): void
    {
        self::skipIfCurlShareIsUnavailable();

        if (self::persistentCurlShareIsAvailable()) {
            $_SERVER['curl_share_init_persistent_fail'] = true;
        }
        $_SERVER['curl_share_setopt_fail'] = \CURL_LOCK_DATA_DNS;

        self::assertNull(CurlShareHandleState::fromOption(TransportSharing::PERSISTENT_PREFER));
    }

    public function testPersistentPreferUsesPersistentSharingWhenAvailable(): void
    {
        self::skipIfPersistentCurlShareIsUnavailable();

        $state = CurlShareHandleState::fromOption(TransportSharing::PERSISTENT_PREFER);

        self::assertInstanceOf(CurlShareHandleState::class, $state);
        self::assertSame(TransportSharing::PERSISTENT_PREFER, $state->mode);
        self::assertPersistentShareWasCreated();
        self::assertArrayNotHasKey('_curl_share_init_count', $_SERVER);
    }

    public function testPersistentRequireRejectsWhenPersistentSharingIsUnavailable(): void
    {
        if (\function_exists('curl_share_init_persistent') && \class_exists('CurlSharePersistentHandle')) {
            self::markTestSkipped('Persistent cURL share handles are available.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('persistent cURL share handle support');

        CurlShareHandleState::fromOption(TransportSharing::PERSISTENT_REQUIRE);
    }

    public function testPersistentRequireRejectsWhenPersistentInitializationFails(): void
    {
        self::skipIfPersistentCurlShareIsUnavailable();

        $_SERVER['curl_share_init_persistent_fail'] = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to create persistent cURL share handle');

        CurlShareHandleState::fromOption(TransportSharing::PERSISTENT_REQUIRE);
    }

    public function testPersistentRequireUsesPersistentSharingWhenAvailable(): void
    {
        self::skipIfPersistentCurlShareIsUnavailable();

        $state = CurlShareHandleState::fromOption(TransportSharing::PERSISTENT_REQUIRE);

        self::assertInstanceOf(CurlShareHandleState::class, $state);
        self::assertSame(TransportSharing::PERSISTENT_REQUIRE, $state->mode);
        self::assertPersistentShareWasCreated();
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

    /**
     * @dataProvider strictTransportSharingModes
     */
    public function testRejectsRequiredSharingWithCustomFactory(string $transportSharing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => $transportSharing,
        ], 'CurlHandler');
    }

    public static function strictTransportSharingModes(): iterable
    {
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
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

    private static function skipIfPersistentCurlShareIsUnavailable(): void
    {
        if (!self::persistentCurlShareIsAvailable()) {
            self::markTestSkipped('Persistent cURL share handles are unavailable.');
        }
    }

    private static function persistentCurlShareIsAvailable(): bool
    {
        return \function_exists('curl_share_init_persistent')
            && \class_exists('CurlSharePersistentHandle')
            && \defined('CURL_LOCK_DATA_DNS')
            && \defined('CURL_LOCK_DATA_CONNECT')
            && \defined('CURL_LOCK_DATA_SSL_SESSION');
    }

    private static function assertHandlerShareWasCreated(): void
    {
        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }

    private static function assertPersistentShareWasCreated(): void
    {
        self::assertSame(1, $_SERVER['_curl_share_init_persistent_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_CONNECT,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share_persistent_options']);
    }
}
