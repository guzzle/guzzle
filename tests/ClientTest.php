<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransportSharing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ClientTest extends TestCase
{
    public function testUsesDefaultHandler()
    {
        $client = new Client();
        Server::enqueue([new Response(200, ['Content-Length' => '0'])]);
        $response = $client->get(Server::$url);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testValidatesArgsForMagicMethods()
    {
        $client = new Client();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Magic request methods require a URI and optional options array');
        $client->options();
    }

    /**
     * @dataProvider magicRequestMethodProvider
     */
    public function testMagicRequestMethodsNormalizeInferredMethodName($method, $expectedMethod)
    {
        $client = new ClientTestMagicClient();
        $options = ['headers' => ['X-Test' => '1']];

        $client->{$method}('/resource', $options);

        self::assertSame([
            ['request', $expectedMethod, '/resource', $options],
        ], $client->calls);
    }

    /**
     * @dataProvider magicAsyncRequestMethodProvider
     */
    public function testMagicAsyncRequestMethodsNormalizeInferredMethodName($method, $expectedMethod)
    {
        $client = new ClientTestMagicClient();
        $options = ['headers' => ['X-Test' => '1']];

        $promise = $client->{$method}('/resource', $options);

        self::assertInstanceOf(PromiseInterface::class, $promise);
        self::assertSame([
            ['requestAsync', $expectedMethod, '/resource', $options],
        ], $client->calls);
    }

    public static function magicRequestMethodProvider()
    {
        return [
            ['options', 'OPTIONS'],
            ['purge', 'PURGE'],
        ];
    }

    public static function magicAsyncRequestMethodProvider()
    {
        return [
            ['optionsAsync', 'OPTIONS'],
            ['purgeAsync', 'PURGE'],
        ];
    }

    public function testCanSendAsyncGetRequests()
    {
        $client = new Client();
        Server::flush();
        Server::enqueue([new Response(200, ['Content-Length' => '2'], 'hi')]);
        $p = $client->getAsync(Server::$url, ['query' => ['test' => 'foo']]);
        self::assertInstanceOf(PromiseInterface::class, $p);
        self::assertSame(200, $p->wait()->getStatusCode());
        $received = Server::received(true);
        self::assertCount(1, $received);
        self::assertSame('test=foo', $received[0]->getUri()->getQuery());
    }

    public function testCanSendSynchronously()
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);
        $request = new Request('GET', 'http://example.com');
        $r = $client->send($request);
        self::assertInstanceOf(ResponseInterface::class, $r);
        self::assertSame(200, $r->getStatusCode());
    }

    public function testEmptyProtocolVersionRequestOptionDefaultsToHttp11()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $client->get('http://example.com', ['version' => '']);

        self::assertSame('1.1', $mock->getLastRequest()->getProtocolVersion());
    }

    public function testEmptyRequestProtocolVersionDefaultsToHttp11()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://example.com', [], null, '');

        $client->send($request);

        self::assertSame('1.1', $mock->getLastRequest()->getProtocolVersion());
    }

    public function testRequestWithUppercaseMethodSendsUppercaseMethod()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $client->request('GET', 'http://foo.com');

        self::assertSame('GET', $mock->getLastRequest()->getMethod());
    }

    public function testRequestAsyncWithUppercaseMethodSendsUppercaseMethod()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $client->requestAsync('POST', 'http://foo.com')->wait();

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
    }

    public function testClientHasOptions()
    {
        $client = new Client([
            'base_uri' => 'http://foo.com',
            'timeout' => 2,
            'headers' => ['bar' => 'baz'],
            'handler' => new MockHandler(),
        ]);
        $config = self::readClientConfig($client);
        self::assertArrayHasKey('base_uri', $config);
        self::assertInstanceOf(Uri::class, $config['base_uri']);
        self::assertSame('http://foo.com', (string) $config['base_uri']);
        self::assertArrayHasKey('handler', $config);
        self::assertNotNull($config['handler']);
        self::assertArrayHasKey('timeout', $config);
        self::assertSame(2, $config['timeout']);
        self::assertSame(['http', 'https'], $config['protocols']);
    }

    public function testTransportSharingIsDisabledByDefault(): void
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share_init_count']);

        try {
            new Client();

            self::assertArrayNotHasKey('_curl_share_init_count', $_SERVER);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testHandlerPreferTransportSharingCreatesDefaultShareHandle(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);

        try {
            new Client([
                'transport_sharing' => TransportSharing::HANDLER_PREFER,
            ]);

            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            self::setCurlVersionInfo($previous);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testHandlerPreferTransportSharingCanBeUsedWithCustomHandler(): void
    {
        $client = new Client([
            'handler' => new MockHandler(),
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        self::assertNull($client->getConfig('transport_sharing'));
    }

    public function testHandlerRequireTransportSharingCannotBeUsedWithCustomHandler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport_sharing');

        new Client([
            'handler' => new MockHandler(),
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
        ]);
    }

    public function testConnectionCapsApplyToDefaultCurlMultiHandler(): void
    {
        self::skipIfDefaultCurlMultiHandlerIsUnavailable();
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_multi']);

        try {
            Server::flush();
            Server::enqueue([new Response()]);

            $client = new Client([
                'max_host_connections' => 1,
                'max_total_connections' => 3,
            ]);

            $response = $client->getAsync(Server::$url, [
                'multiplex' => Multiplexing::WAIT,
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
            self::assertSame(3, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_TOTAL_CONNECTIONS')]);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_multi']);
        }
    }

    public function testConnectionCapsApplyToSyncRequests(): void
    {
        self::skipIfDefaultCurlMultiHandlerIsUnavailable();
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_multi']);

        try {
            Server::flush();
            Server::enqueue([new Response()]);

            $client = new Client([
                'max_host_connections' => 1,
                'max_total_connections' => 3,
            ]);

            $response = $client->get(Server::$url);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
            self::assertSame(3, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_TOTAL_CONNECTIONS')]);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_multi']);
        }
    }

    /**
     * @dataProvider clientConnectionCapOptionProvider
     */
    public function testConnectionCapsRejectStreamRequests(string $option): void
    {
        self::skipIfStreamHandlerIsUnavailable();

        $client = new Client([$option => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabling the "stream" request option on a stream handler configured with the "max_host_connections" or "max_total_connections" option is not supported because streamed connections cannot be capped.');

        $client->get('http://localhost/', ['stream' => true]);
    }

    public static function clientConnectionCapOptionProvider(): iterable
    {
        yield 'max host connections' => ['max_host_connections'];
        yield 'max total connections' => ['max_total_connections'];
    }

    public function testConnectionCapsRejectStreamRequestsWithARejectedPromise(): void
    {
        self::skipIfStreamHandlerIsUnavailable();

        $client = new Client(['max_total_connections' => 1]);
        $promise = $client->getAsync('http://localhost/', ['stream' => true]);

        // The configuration error surfaces through the promise chain rather
        // than being thrown before a promise is returned.
        self::assertInstanceOf(PromiseInterface::class, $promise);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabling the "stream" request option on a stream handler configured with the "max_host_connections" or "max_total_connections" option is not supported because streamed connections cannot be capped.');

        $promise->wait();
    }

    public function testConnectionCapsFallBackToStreamHandlerWhenCurlCannotApplyThem(): void
    {
        self::skipIfStreamHandlerIsUnavailable();
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.29.0', 'features' => 0]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_multi']);

        try {
            Server::flush();
            Server::enqueue([new Response()]);

            $client = new Client(['max_host_connections' => 1]);
            $response = $client->get(Server::$url);

            self::assertSame(200, $response->getStatusCode());
            self::assertArrayNotHasKey('_curl_multi', $_SERVER);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
            unset($_SERVER['curl_test'], $_SERVER['_curl_multi']);
        }
    }

    public function testConnectionCapsRejectStreamRequestsWhenCurlCannotApplyThem(): void
    {
        self::skipIfStreamHandlerIsUnavailable();
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.29.0', 'features' => 0]);

        try {
            $client = new Client(['max_host_connections' => 1]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Enabling the "stream" request option on a stream handler configured with the "max_host_connections" or "max_total_connections" option is not supported because streamed connections cannot be capped.');

            $client->get('http://localhost/', ['stream' => true]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider connectionCapClientOptionProvider
     */
    public function testConnectionCapClientOptionsCannotBeUsedWithCustomHandler(string $option): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configure the options on the CurlMultiHandler constructor to apply numeric connection caps, or on the StreamHandler constructor to reject enabled response streaming, when providing a custom handler.');

        new Client([
            'handler' => new MockHandler(),
            $option => 1,
        ]);
    }

    public function testNullConnectionCapClientOptionsCanBeUsedWithCustomHandler(): void
    {
        $client = new Client([
            'handler' => new MockHandler(),
            'max_host_connections' => null,
            'max_total_connections' => null,
        ]);

        self::assertNull($client->getConfig('max_host_connections'));
        self::assertNull($client->getConfig('max_total_connections'));
    }

    /**
     * @dataProvider invalidConnectionCapClientOptionProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidConnectionCapClientOptionsWhenCurlCannotApplyThem(string $option, $value): void
    {
        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.29.0', 'features' => 0]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($option.' must be a positive integer.');

            new Client([$option => $value]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testConnectionCapsComposeWithTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        self::skipIfDefaultCurlMultiHandlerIsUnavailable();
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);

        try {
            Server::flush();
            Server::enqueue([new Response()]);

            $client = new Client([
                'transport_sharing' => TransportSharing::HANDLER_PREFER,
                'max_host_connections' => 1,
            ]);

            $response = $client->getAsync(Server::$url, [
                'multiplex' => Multiplexing::WAIT,
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            self::setCurlVersionInfo($previous);
            unset($_SERVER['curl_test'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public static function connectionCapClientOptionProvider(): iterable
    {
        yield 'max host connections' => ['max_host_connections'];
        yield 'max total connections' => ['max_total_connections'];
    }

    public static function invalidConnectionCapClientOptionProvider(): iterable
    {
        foreach (['max_host_connections', 'max_total_connections'] as $option) {
            yield $option.' zero' => [$option, 0];
            yield $option.' negative' => [$option, -1];
            yield $option.' float' => [$option, 1.0];
            yield $option.' string' => [$option, '1'];
        }
    }

    public function testTransportSharingNullCanBeUsedWithCustomHandler(): void
    {
        $client = new Client([
            'handler' => new MockHandler(),
            'transport_sharing' => null,
        ]);

        self::assertNull($client->getConfig('transport_sharing'));
    }

    public function testTransportSharingNoneCanBeUsedWithCustomHandler(): void
    {
        $client = new Client([
            'handler' => new MockHandler(),
            'transport_sharing' => TransportSharing::NONE,
        ]);

        self::assertNull($client->getConfig('transport_sharing'));
    }

    /**
     * @dataProvider invalidTransportSharingOptions
     *
     * @param mixed $transportSharing
     */
    public function testTransportSharingRejectsInvalidValues($transportSharing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport_sharing');

        new Client([
            'transport_sharing' => $transportSharing,
        ]);
    }

    public static function invalidTransportSharingOptions(): iterable
    {
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[]];
        yield 'string' => ['dns'];
    }

    public function testCanMergeOnBaseUri()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'base_uri' => 'http://foo.com/bar/',
            'handler' => $mock,
        ]);
        $client->get('baz');
        self::assertSame(
            'http://foo.com/bar/baz',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testCanMergeOnBaseUriWithRequest()
    {
        $mock = new MockHandler([new Response(), new Response()]);
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://foo.com/bar/',
        ]);
        $client->request('GET', new Uri('baz'));
        self::assertSame(
            'http://foo.com/bar/baz',
            (string) $mock->getLastRequest()->getUri()
        );

        $client->request('GET', new Uri('baz'), ['base_uri' => 'http://example.com/foo/']);
        self::assertSame(
            'http://example.com/foo/baz',
            (string) $mock->getLastRequest()->getUri(),
            'Can overwrite the base_uri through the request options'
        );
    }

    public function testCanUseRelativeUriWithSend()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://bar.com',
        ]);
        $config = self::readClientConfig($client);
        self::assertSame('http://bar.com', (string) $config['base_uri']);
        $request = new Request('GET', '/baz');
        $client->send($request);
        self::assertSame(
            'http://bar.com/baz',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testMergesDefaultOptionsAndDoesNotOverwriteUa()
    {
        $client = new Client(['headers' => ['User-agent' => 'foo']]);
        $config = self::readClientConfig($client);
        self::assertSame(['User-agent' => 'foo'], $config['headers']);
        self::assertIsArray($config['allow_redirects']);
        self::assertTrue($config['http_errors']);
        self::assertTrue($config['decode_content']);
        self::assertTrue($config['verify']);
    }

    public function testDoesNotOverwriteHeaderWithDefault()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock,
        ]);
        $c->get('http://example.com', ['headers' => ['User-Agent' => 'bar']]);
        self::assertSame('bar', $mock->getLastRequest()->getHeaderLine('User-Agent'));
    }

    private static function skipIfDefaultCurlHandlerIsUnavailable(): void
    {
        if (
            !\function_exists('curl_share_init')
            || !\function_exists('curl_share_setopt')
            || !\function_exists('curl_exec')
            || !CurlVersion::supportsCurlHandler()
        ) {
            self::markTestSkipped('Default cURL handler with share handles is unavailable.');
        }
    }

    private static function skipIfDefaultCurlMultiHandlerIsUnavailable(): void
    {
        if (!\function_exists('curl_multi_exec') || !\function_exists('curl_exec') || !CurlVersion::supportsCurlHandler()) {
            self::markTestSkipped('Default cURL multi handler is unavailable.');
        }
    }

    private static function skipIfConnectionCapCurlMultiOptionsUnavailable(): void
    {
        if (!CurlVersion::supportsConnectionCaps()) {
            self::markTestSkipped('cURL multi connection cap options are unavailable.');
        }
    }

    private static function skipIfStreamHandlerIsUnavailable(): void
    {
        if (!\ini_get('allow_url_fopen')) {
            self::markTestSkipped('Stream handler is unavailable.');
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

    public function testDoesNotOverwriteHeaderWithDefaultInRequest()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock,
        ]);
        $request = new Request('GET', Server::$url, ['User-Agent' => 'bar']);
        $c->send($request);
        self::assertSame('bar', $mock->getLastRequest()->getHeaderLine('User-Agent'));
    }

    public function testDoesOverwriteHeaderWithSetRequestOption()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock,
        ]);
        $request = new Request('GET', Server::$url, ['User-Agent' => 'bar']);
        $c->send($request, ['headers' => ['User-Agent' => 'YO']]);
        self::assertSame('YO', $mock->getLastRequest()->getHeaderLine('User-Agent'));
    }

    public function testCanUnsetRequestOptionWithNull()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['foo' => 'bar'],
            'handler' => $mock,
        ]);
        $c->get('http://example.com', ['headers' => null]);
        self::assertFalse($mock->getLastRequest()->hasHeader('foo'));
    }

    public function testAllowRedirectsCanBeTrue()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://foo.com', ['allow_redirects' => true]);
        self::assertIsArray($mock->getLastOptions()['allow_redirects']);
    }

    public function testValidatesAllowRedirects()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_redirects must be true, false, or array');
        $client->get('http://foo.com', ['allow_redirects' => 'foo']);
    }

    public function testThrowsHttpErrorsByDefault()
    {
        $mock = new MockHandler([new Response(404)]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        $client->get('http://foo.com');
    }

    public function testValidatesCookies()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cookies must be an instance of GuzzleHttp\\Cookie\\CookieJarInterface');
        $client->get('http://foo.com', ['cookies' => 'foo']);
    }

    public function testSetCookieToTrueUsesSharedJar()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'foo=bar']),
            new Response(),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler, 'cookies' => true]);
        $client->get('http://foo.com');
        $client->get('http://foo.com');
        self::assertSame('foo=bar', $mock->getLastRequest()->getHeaderLine('Cookie'));
    }

    public function testSetCookieToJar()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'foo=bar']),
            new Response(),
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $jar = new CookieJar();
        $client->get('http://foo.com', ['cookies' => $jar]);
        $client->get('http://foo.com', ['cookies' => $jar]);
        self::assertSame('foo=bar', $mock->getLastRequest()->getHeaderLine('Cookie'));
    }

    public function testCanDisableContentDecoding()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => false]);
        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Accept-Encoding'));
        self::assertFalse($mock->getLastOptions()['decode_content']);
    }

    public function testCanSetContentDecodingToValue()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => 'gzip']);
        $last = $mock->getLastRequest();
        self::assertSame('gzip', $last->getHeaderLine('Accept-Encoding'));
        self::assertSame('gzip', $mock->getLastOptions()['decode_content']);
    }

    public function testCanSetContentDecodingToZeroString()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => '0']);
        $last = $mock->getLastRequest();
        self::assertSame('0', $last->getHeaderLine('Accept-Encoding'));
        self::assertSame('0', $mock->getLastOptions()['decode_content']);
    }

    public function testAddsAcceptEncodingbyCurl()
    {
        $client = new Client(['curl' => [\CURLOPT_ENCODING => '']]);

        Server::flush();
        Server::enqueue([new Response()]);
        $client->get(Server::$url);
        $sent = Server::received()[0];
        self::assertTrue($sent->hasHeader('Accept-Encoding'));

        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'curl' => [\CURLOPT_ENCODING => ''],
            'handler' => $mock,
        ]);
        $client->get('http://foo.com');
        self::assertSame([\CURLOPT_ENCODING => ''], $mock->getLastOptions()['curl']);
    }

    public function testValidatesHeaders()
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('http://foo.com', ['headers' => 'foo']);
    }

    public function testAddsBody()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['body' => 'foo']);
        $last = $mock->getLastRequest();
        self::assertSame('foo', (string) $last->getBody());
    }

    public function testValidatesQuery()
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');

        $this->expectException(\InvalidArgumentException::class);
        $client->send($request, ['query' => false]);
    }

    public function testQueryCanBeString()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => 'foo']);
        self::assertSame('foo', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testQueryCanBeArray()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => ['foo' => 'bar baz']]);
        self::assertSame('foo=bar%20baz', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testCanAddJsonData()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['json' => ['foo' => 'bar']]);
        $last = $mock->getLastRequest();
        self::assertSame('{"foo":"bar"}', (string) $mock->getLastRequest()->getBody());
        self::assertSame('application/json', $last->getHeaderLine('Content-Type'));
    }

    public function testCanAddJsonDataWithoutOverwritingContentType()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, [
            'headers' => ['content-type' => 'foo'],
            'json' => 'a',
        ]);
        $last = $mock->getLastRequest();
        self::assertSame('"a"', (string) $mock->getLastRequest()->getBody());
        self::assertSame('foo', $last->getHeaderLine('Content-Type'));
    }

    public function testCanAddJsonDataWithNullHeader()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, [
            'headers' => null,
            'json' => 'a',
        ]);
        $last = $mock->getLastRequest();
        self::assertSame('"a"', (string) $mock->getLastRequest()->getBody());
        self::assertSame('application/json', $last->getHeaderLine('Content-Type'));
    }

    public function testAuthCanBeDisabledWithNull()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => null]);

        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeDisabledWithFalse()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => false]);

        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeCustomStringForHandlers()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => 'custom']);

        self::assertSame('custom', $mock->getLastOptions()['auth']);
    }

    public function testAuthCanBeArrayForBasicAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b']]);
        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanUseNullTypeForDefaultBasicAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', null]]);
        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanBeArrayForDigestAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'digest']]);
        $last = $mock->getLastOptions();
        self::assertSame([
            \CURLOPT_HTTPAUTH => 2,
            \CURLOPT_USERPWD => 'a:b',
        ], $last['curl']);
    }

    public function testCanAddFormParams()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'form_params' => [
                'foo' => 'bar bam',
                'baz' => ['boo' => 'qux'],
            ],
        ]);
        $last = $mock->getLastRequest();
        self::assertSame(
            'application/x-www-form-urlencoded',
            $last->getHeaderLine('Content-Type')
        );
        self::assertSame(
            'foo=bar+bam&baz%5Bboo%5D=qux',
            (string) $last->getBody()
        );
    }

    public function testFormParamsAcceptScalarAndNullValues()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'form_params' => [
                'int' => 1,
                'float' => 1.5,
                'true' => true,
                'false' => false,
                'null' => null,
                'nested' => ['value' => 2],
            ],
        ]);

        $last = $mock->getLastRequest();
        self::assertSame(
            'int=1&float=1.5&true=1&false=0&nested%5Bvalue%5D=2',
            (string) $last->getBody()
        );
    }

    public function testTlsPassphraseOptionsAcceptNullPasswordSlot()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', [
            'cert' => [__FILE__, null],
            'ssl_key' => [__FILE__, null],
        ]);

        self::assertSame([__FILE__, null], $mock->getLastOptions()['cert']);
        self::assertSame([__FILE__, null], $mock->getLastOptions()['ssl_key']);
    }

    public function testFormParamsEncodedProperly()
    {
        $separator = \ini_get('arg_separator.output');
        \ini_set('arg_separator.output', '&amp;');
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'form_params' => [
                'foo' => 'bar bam',
                'baz' => ['boo' => 'qux'],
            ],
        ]);
        $last = $mock->getLastRequest();
        self::assertSame(
            'foo=bar+bam&baz%5Bboo%5D=qux',
            (string) $last->getBody()
        );

        \ini_set('arg_separator.output', $separator);
    }

    public function testEnsuresThatFormParamsAndMultipartAreExclusive()
    {
        $client = new Client(['handler' => static function () {
        }]);

        $this->expectException(\InvalidArgumentException::class);
        $client->post('http://foo.com', [
            'form_params' => ['foo' => 'bar bam'],
            'multipart' => [],
        ]);
    }

    public function testCanSendMultipart()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'multipart' => [
                [
                    'name' => 'foo',
                    'contents' => 'bar',
                ],
                [
                    'name' => 'test',
                    'contents' => \fopen(__FILE__, 'r'),
                ],
            ],
        ]);

        $last = $mock->getLastRequest();
        self::assertStringContainsString(
            'multipart/form-data; boundary=',
            $last->getHeaderLine('Content-Type')
        );

        self::assertStringContainsString(
            'Content-Disposition: form-data; name="foo"',
            (string) $last->getBody()
        );

        self::assertStringContainsString('bar', (string) $last->getBody());
        self::assertStringContainsString(
            'Content-Disposition: form-data; name="foo"'."\r\n",
            (string) $last->getBody()
        );
        self::assertStringContainsString(
            'Content-Disposition: form-data; name="test"; filename="ClientTest.php"',
            (string) $last->getBody()
        );
    }

    public function testCanSendMultipartWithExplicitBody()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(
            new Request(
                'POST',
                'http://foo.com',
                [],
                new Psr7\MultipartStream(
                    [
                        [
                            'name' => 'foo',
                            'contents' => 'bar',
                        ],
                        [
                            'name' => 'test',
                            'contents' => \fopen(__FILE__, 'r'),
                        ],
                    ]
                )
            )
        );

        $last = $mock->getLastRequest();
        self::assertStringContainsString(
            'multipart/form-data; boundary=',
            $last->getHeaderLine('Content-Type')
        );

        self::assertStringContainsString(
            'Content-Disposition: form-data; name="foo"',
            (string) $last->getBody()
        );

        self::assertStringContainsString('bar', (string) $last->getBody());
        self::assertStringContainsString(
            'Content-Disposition: form-data; name="foo"'."\r\n",
            (string) $last->getBody()
        );
        self::assertStringContainsString(
            'Content-Disposition: form-data; name="test"; filename="ClientTest.php"',
            (string) $last->getBody()
        );
    }

    public function testUsesProxyEnvironmentVariables()
    {
        // Snapshot the proxy environment so the assertions below run against a
        // known-empty state and the original values are restored afterwards,
        // rather than clobbering an inherited proxy env for later tests.
        $names = ['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'];
        $previousEnv = [];
        $previousServer = [];
        foreach ($names as $name) {
            $previousEnv[$name] = \getenv($name, true);
            $previousServer[$name] = $_SERVER[$name] ?? null;
            unset($_SERVER[$name]);
            \putenv($name);
        }

        try {
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayNotHasKey('proxy', $config);

            \putenv('HTTP_PROXY=127.0.0.1');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['http' => '127.0.0.1'], $config['proxy']);

            \putenv('HTTPS_PROXY=127.0.0.2');
            \putenv('NO_PROXY=127.0.0.3, 127.0.0.4');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(
                ['http' => '127.0.0.1', 'https' => '127.0.0.2', 'no' => ['127.0.0.3', '127.0.0.4']],
                $config['proxy']
            );
        } finally {
            foreach ($names as $name) {
                if (false === $previousEnv[$name]) {
                    \putenv($name);
                } else {
                    \putenv($name.'='.$previousEnv[$name]);
                }

                if (null === $previousServer[$name]) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $previousServer[$name];
                }
            }
        }
    }

    public function testNullProxyValuesAreAccepted()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $client->get('http://foo.com', [
            'proxy' => [
                'http' => null,
                'https' => null,
                'no' => null,
            ],
        ]);

        self::assertSame(
            ['http' => null, 'https' => null, 'no' => null],
            $mock->getLastOptions()['proxy']
        );
    }

    public function testRequestSendsWithSync()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->request('GET', 'http://foo.com');
        self::assertTrue($mock->getLastOptions()['synchronous']);
    }

    public function testSendSendsWithSync()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request('GET', 'http://foo.com'));
        self::assertTrue($mock->getLastOptions()['synchronous']);
    }

    public function testSendWithInvalidHeader()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $client->send($request, ['headers' => ['X-Foo: Bar']]);
    }

    public function testSendWithInvalidHeaders()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $client->send($request, ['headers' => ['X-Foo: Bar', 'X-Test: Fail']]);
    }

    public function testDefaultHeadersHandleNumericHeaderNames()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler' => $mock,
            'headers' => ['0' => 'default'],
        ]);

        $client->send(new Request('GET', 'http://foo.com', ['0' => 'request']));

        $sent = $mock->getLastRequest();
        self::assertNotNull($sent);
        self::assertSame(['request'], $sent->getHeader('0'));
    }

    public function testRequestHeadersHandleNumericHeaderNames()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');

        $client->send($request, ['headers' => ['X-Foo' => 'bar', '0' => 'zero']]);

        $sent = $mock->getLastRequest();
        self::assertNotNull($sent);
        self::assertSame(['bar'], $sent->getHeader('X-Foo'));
        self::assertSame(['zero'], $sent->getHeader('0'));
    }

    public function testEasyRequestHeadersHandleNumericHeaderNames()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $client->request('GET', 'http://foo.com', ['headers' => ['0' => 'zero']]);

        $sent = $mock->getLastRequest();
        self::assertNotNull($sent);
        self::assertSame(['zero'], $sent->getHeader('0'));
    }

    public function testEasyRequestHeadersPreserveStringValueArrays()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $client->request('GET', 'http://foo.com', ['headers' => ['X-Foo' => ['bar', 'baz']]]);

        $sent = $mock->getLastRequest();
        self::assertNotNull($sent);
        self::assertSame(['bar', 'baz'], $sent->getHeader('X-Foo'));
    }

    public function testProperlyBuildsQuery()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => ['foo' => 'bar', 'john' => 'doe']]);
        self::assertSame('foo=bar&john=doe', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testSendSendsWithIpAddressAndPortAndHostHeaderInRequestTheHostShouldBePreserved()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['base_uri' => 'http://127.0.0.1:8585', 'handler' => $mockHandler]);
        $request = new Request('GET', '/test', ['Host' => 'foo.com']);

        $client->send($request);

        self::assertSame('foo.com', $mockHandler->getLastRequest()->getHeader('Host')[0]);
    }

    public function testSendSendsWithDomainAndHostHeaderInRequestTheHostShouldBePreserved()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['base_uri' => 'http://foo2.com', 'handler' => $mockHandler]);
        $request = new Request('GET', '/test', ['Host' => 'foo.com']);

        $client->send($request);

        self::assertSame('foo.com', $mockHandler->getLastRequest()->getHeader('Host')[0]);
    }

    public function testValidatesSink()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('http://test.com', ['sink' => true]);
    }

    public function testHttpDefaultSchemeIfUriHasNone()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', '//example.org/test');

        self::assertSame('http://example.org/test', (string) $mockHandler->getLastRequest()->getUri());
    }

    /**
     * @dataProvider partialUriProvider
     */
    public function testMockHandlerReceivesPartialUri($uri)
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', $uri);

        self::assertSame($uri, (string) $mockHandler->getLastRequest()->getUri());
    }

    public static function partialUriProvider()
    {
        return [
            'relative path' => ['baz'],
            'host-like relative path' => ['gstatic.com/generate_204'],
            'path starting with colon-slash-slash' => ['://gstatic.com/generate_204'],
            'absolute path' => ['/generate_204'],
        ];
    }

    public function testMockHandlerReceivesPartialRequestUri()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->send(new Request('GET', '/baz'));

        self::assertSame('/baz', (string) $mockHandler->getLastRequest()->getUri());
    }

    public function testMiddlewareCanRewritePartialUriBeforeHandler()
    {
        $mockHandler = new MockHandler([new Response()]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push(static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $uri = Psr7\UriResolver::resolve(new Uri('https://example.com/base/'), $request->getUri());

                return $handler($request->withUri($uri), $options);
            };
        });
        $client = new Client(['handler' => $stack]);

        $client->request('GET', 'some/path');

        self::assertSame('https://example.com/base/some/path', (string) $mockHandler->getLastRequest()->getUri());
    }

    /**
     * @dataProvider versionProvider
     */
    public function testNormalizesVersionOption($version, string $expected)
    {
        $mockHandler = new MockHandler([new Response(), new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', 'http://example.com', [RequestOptions::VERSION => $version]);
        self::assertSame(
            $expected,
            $mockHandler->getLastRequest()->getProtocolVersion()
        );

        $request = new Request('GET', 'http://example.com');
        $client->send($request, [RequestOptions::VERSION => $version]);
        self::assertSame(
            $expected,
            $mockHandler->getLastRequest()->getProtocolVersion()
        );
    }

    public static function versionProvider(): iterable
    {
        yield ['1.0', '1.0'];
        yield [1.0, '1.0'];
        yield ['1.1', '1.1'];
        yield [1.1, '1.1'];
        yield ['2', '2'];
        yield [2, '2'];
        yield [2.0, '2.0'];
    }

    public function testSendPreservesCustomRequestWhenApplyingRequestOptions()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);
        $request = new ClientTestRequest('POST', new ClientTestUri('http://foo.com/path'));

        $client->send($request, [
            RequestOptions::HEADERS => ['X-Test' => '1'],
            RequestOptions::BODY => 'payload',
            RequestOptions::QUERY => ['a' => 'b'],
            RequestOptions::VERSION => 1.0,
        ]);

        $lastRequest = $mockHandler->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $lastRequest);
        self::assertInstanceOf(ClientTestUri::class, $lastRequest->getUri());
        self::assertSame('http://foo.com/path?a=b', (string) $lastRequest->getUri());
        self::assertSame('1', $lastRequest->getHeaderLine('X-Test'));
        self::assertSame('payload', (string) $lastRequest->getBody());
        self::assertSame('1.0', $lastRequest->getProtocolVersion());
    }

    public function testSendPreservesCustomUriWhenMergingBaseUri()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client([
            'handler' => $mockHandler,
            'base_uri' => new ClientTestUri('http://foo.com/base/'),
        ]);
        $request = new ClientTestRequest('GET', new ClientTestUri('relative'));

        $client->send($request);

        $lastRequest = $mockHandler->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $lastRequest);
        self::assertInstanceOf(ClientTestUri::class, $lastRequest->getUri());
        self::assertSame('http://foo.com/base/relative', (string) $lastRequest->getUri());
    }

    public function testHandlerIsCallable()
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(['handler' => 'not_cllable']);
    }

    public function testResponseBodyAsString()
    {
        $responseBody = '{ "package": "guzzle" }';
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $responseBody)]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');
        $response = $client->send($request, ['json' => ['a' => 'b']]);

        self::assertSame($responseBody, (string) $response->getBody());
    }

    public function testResponseContent()
    {
        $responseBody = '{ "package": "guzzle" }';
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $responseBody)]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('POST', 'http://foo.com');
        $response = $client->send($request, ['json' => ['a' => 'b']]);

        self::assertSame($responseBody, $response->getBody()->getContents());
    }

    public function testIdnSupportDefaultValue()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $config = self::readClientConfig($client);

        self::assertFalse($config['idn_conversion']);
    }

    /**
     * @requires extension idn
     */
    public function testIdnIsTranslatedToAsciiWhenConversionIsEnabled()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', 'https://яндекс.рф/images', ['idn_conversion' => true]);

        $request = $mockHandler->getLastRequest();

        self::assertSame('https://xn--d1acpjx3f.xn--p1ai/images', (string) $request->getUri());
        self::assertSame('xn--d1acpjx3f.xn--p1ai', (string) $request->getHeaderLine('Host'));
    }

    public function testIdnStaysTheSameWhenConversionIsDisabled()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', 'https://яндекс.рф/images', ['idn_conversion' => false]);

        $request = $mockHandler->getLastRequest();

        self::assertSame('https://яндекс.рф/images', (string) $request->getUri());
        self::assertSame('яндекс.рф', (string) $request->getHeaderLine('Host'));
    }

    public function testIdnConversionRejectsInvalidValue()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('idn_conversion must be true, false, null, or an integer IDNA_* bitmask');

        $client->request('GET', 'https://example.com', ['idn_conversion' => 'invalid']);
    }

    /**
     * @requires extension idn
     */
    public function testExceptionOnInvalidIdn()
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('IDN conversion failed');
        $client->request('GET', 'https://-яндекс.рф/images', ['idn_conversion' => true]);
    }

    /**
     * @depends testCanUseRelativeUriWithSend
     *
     * @requires extension idn
     */
    public function testIdnBaseUri()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://яндекс.рф',
            'idn_conversion' => true,
        ]);
        $config = self::readClientConfig($client);
        self::assertSame('http://яндекс.рф', (string) $config['base_uri']);
        $request = new Request('GET', '/baz');
        $client->send($request);
        self::assertSame('http://xn--d1acpjx3f.xn--p1ai/baz', (string) $mock->getLastRequest()->getUri());
        self::assertSame('xn--d1acpjx3f.xn--p1ai', (string) $mock->getLastRequest()->getHeaderLine('Host'));
    }

    /**
     * @requires extension idn
     */
    public function testIdnWithRedirect()
    {
        $mockHandler = new MockHandler([
            new Response(302, ['Location' => 'http://www.tést.com/whatever']),
            new Response(),
        ]);
        $handler = HandlerStack::create($mockHandler);
        $requests = [];
        $handler->push(Middleware::history($requests));
        $client = new Client(['handler' => $handler]);

        $client->request('GET', 'https://яндекс.рф/images', [
            RequestOptions::ALLOW_REDIRECTS => [
                'referer' => true,
                'track_redirects' => true,
            ],
            'idn_conversion' => true,
        ]);

        $request = $mockHandler->getLastRequest();

        self::assertSame('http://www.xn--tst-bma.com/whatever', (string) $request->getUri());
        self::assertSame('www.xn--tst-bma.com', (string) $request->getHeaderLine('Host'));

        $request = $requests[0]['request'];
        self::assertSame('https://xn--d1acpjx3f.xn--p1ai/images', (string) $request->getUri());
        self::assertSame('xn--d1acpjx3f.xn--p1ai', (string) $request->getHeaderLine('Host'));
    }

    private static function readClientConfig(Client $client): array
    {
        $readConfig = \Closure::bind(static function (Client $client): array {
            return $client->config;
        }, null, Client::class);

        return $readConfig($client);
    }
}

final class ClientTestRequest extends Request
{
}

final class ClientTestUri extends Uri
{
}

final class ClientTestMagicClient extends Client
{
    public $calls = [];

    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $this->calls[] = ['request', $method, $uri, $options];

        return new Response();
    }

    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        $this->calls[] = ['requestAsync', $method, $uri, $options];

        return new FulfilledPromise(new Response());
    }
}

final class ClientTestStringable
{
    /** @var string */
    private $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
