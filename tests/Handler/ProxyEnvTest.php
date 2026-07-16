<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\ProxyEnv;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\ProxyEnv
 */
class ProxyEnvTest extends TestCase
{
    public function testReturnsNullWhenNothingIsSet(): void
    {
        self::withProxyEnvironment([], static function (): void {
            self::assertNull(ProxyEnv::getProxyForScheme('http'));
            self::assertNull(ProxyEnv::getProxyForScheme('https'));
            self::assertNull(ProxyEnv::getNoProxy());
        });
    }

    public function testReadsLowercaseSchemeProxy(): void
    {
        self::withProxyEnvironment([
            'http_proxy' => 'http://http-proxy.example.com:8125',
            'https_proxy' => 'http://https-proxy.example.com:8125',
        ], static function (): void {
            self::assertSame('http://http-proxy.example.com:8125', ProxyEnv::getProxyForScheme('http'));
            self::assertSame('http://https-proxy.example.com:8125', ProxyEnv::getProxyForScheme('https'));
        });
    }

    public function testReadsUppercaseSchemeProxyForHttps(): void
    {
        self::withProxyEnvironment(['HTTPS_PROXY' => 'http://proxy.example.com:8125'], static function (): void {
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('https'));
        });
    }

    public function testNeverReadsUppercaseHttpProxy(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment(['HTTP_PROXY' => 'http://proxy.example.com:8125'], static function (): void {
            self::assertNull(ProxyEnv::getProxyForScheme('http'));
        });
    }

    public function testLowercaseTakesPrecedenceOverUppercase(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment([
            'https_proxy' => 'http://lower.example.com:8125',
            'HTTPS_PROXY' => 'http://upper.example.com:8125',
        ], static function (): void {
            self::assertSame('http://lower.example.com:8125', ProxyEnv::getProxyForScheme('https'));
        });
    }

    public function testFallsBackToAllProxy(): void
    {
        self::withProxyEnvironment(['all_proxy' => 'http://proxy.example.com:8125'], static function (): void {
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('http'));
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('https'));
        });

        self::withProxyEnvironment(['ALL_PROXY' => 'http://proxy.example.com:8125'], static function (): void {
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('http'));
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('https'));
        });
    }

    public function testSchemeSpecificProxyTakesPrecedenceOverAllProxy(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => 'http://scheme.example.com:8125',
            'ALL_PROXY' => 'http://all.example.com:8125',
        ], static function (): void {
            self::assertSame('http://scheme.example.com:8125', ProxyEnv::getProxyForScheme('https'));
            self::assertSame('http://all.example.com:8125', ProxyEnv::getProxyForScheme('http'));
        });
    }

    public function testTreatsEmptyValueAsUnset(): void
    {
        self::withProxyEnvironment([
            'https_proxy' => '',
            'ALL_PROXY' => 'http://proxy.example.com:8125',
        ], static function (): void {
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('https'));
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('http'));
        });

        self::withProxyEnvironment(['https_proxy' => ''], static function (): void {
            self::assertNull(ProxyEnv::getProxyForScheme('https'));
        });
    }

    public function testSchemeIsNormalizedToLowercase(): void
    {
        self::withProxyEnvironment(['https_proxy' => 'http://proxy.example.com:8125'], static function (): void {
            self::assertSame('http://proxy.example.com:8125', ProxyEnv::getProxyForScheme('HTTPS'));
        });
    }

    public function testReadsNoProxy(): void
    {
        self::withProxyEnvironment(['no_proxy' => '10.0.0.0/8,example.com'], static function (): void {
            self::assertSame('10.0.0.0/8,example.com', ProxyEnv::getNoProxy());
        });

        self::withProxyEnvironment(['NO_PROXY' => 'example.com'], static function (): void {
            self::assertSame('example.com', ProxyEnv::getNoProxy());
        });
    }

    public function testLowercaseNoProxyTakesPrecedence(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment([
            'no_proxy' => 'lower.example.com',
            'NO_PROXY' => 'upper.example.com',
        ], static function (): void {
            self::assertSame('lower.example.com', ProxyEnv::getNoProxy());
        });
    }

    public function testTreatsEmptyNoProxyAsUnset(): void
    {
        self::withProxyEnvironment([
            'no_proxy' => '',
            'NO_PROXY' => 'example.com',
        ], static function (): void {
            self::assertSame('example.com', ProxyEnv::getNoProxy());
        });

        self::withProxyEnvironment(['no_proxy' => ''], static function (): void {
            self::assertNull(ProxyEnv::getNoProxy());
        });
    }

    public function testSplitsNoProxyOnCommasAndBlanks(): void
    {
        self::assertSame(
            ['host1.test', 'host2.test', 'host3.test', 'host4.test'],
            ProxyEnv::splitNoProxy("host1.test host2.test,host3.test ,\thost4.test")
        );
    }

    public function testPreservesLeadingDotsInNoProxyEntries(): void
    {
        self::assertSame(
            ['.example.com', '..foo.com'],
            ProxyEnv::splitNoProxy('.example.com,..foo.com')
        );
    }

    public function testDropsEmptyNoProxyEntries(): void
    {
        self::assertSame(['.'], ProxyEnv::splitNoProxy(' ,, . '));
        self::assertSame([], ProxyEnv::splitNoProxy(' ,, '));
    }

    public function testResolveProxySelectionPrefersOptionThenEnvironment(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment([
            'http_proxy' => 'http://env.example.com:8125',
            'NO_PROXY' => 'blocked.example.com',
        ], static function (): void {
            $uri = new Uri('http://example.com');

            // An explicit option decision wins over the environment.
            self::assertSame(
                'http://option.example.com:8125',
                ProxyEnv::resolveProxySelection($uri, 'http://option.example.com:8125')->getProxy()
            );

            // No option decision falls back to the environment proxy.
            self::assertSame(
                'http://env.example.com:8125',
                ProxyEnv::resolveProxySelection($uri, null)->getProxy()
            );

            // An environment no_proxy match bypasses the environment proxy.
            self::assertNull(
                ProxyEnv::resolveProxySelection(new Uri('http://blocked.example.com'), null)->getProxy()
            );
        });
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
}
