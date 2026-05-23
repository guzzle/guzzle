<?php

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlShare;
use GuzzleHttp\Handler\CurlShareHandleState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\CurlShare
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
        self::assertNull(CurlShareHandleState::fromOption(CurlShare::NONE));
    }

    public function testHandlerModeCreatesShareHandleForDnsAndSslSession(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $state = CurlShareHandleState::fromOption(CurlShare::HANDLER);

        self::assertInstanceOf(CurlShareHandleState::class, $state);
        self::assertSame(CurlShare::HANDLER, $state->mode);
        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);

        if (\defined('CURL_LOCK_DATA_CONNECT')) {
            self::assertNotContains(\constant('CURL_LOCK_DATA_CONNECT'), $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        }
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

    public function testRejectsShareWithCustomFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        CurlShareHandleState::assertNoCustomFactoryConflict([
            'handle_factory' => new CurlFactory(0),
            'share' => CurlShare::HANDLER,
        ], 'CurlHandler');
    }

    public function testAllowsDisabledShareWithCustomFactory(): void
    {
        CurlShareHandleState::assertNoCustomFactoryConflict([
            'handle_factory' => new CurlFactory(0),
            'share' => CurlShare::NONE,
        ], 'CurlHandler');

        self::assertTrue(true);
    }

    public function testCurlShareSetoptFailureThrows(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $_SERVER['curl_share_setopt_fail'] = \CURL_LOCK_DATA_DNS;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to configure cURL share handle');

        CurlShareHandleState::fromOption(CurlShare::HANDLER);
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt')) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }
}
