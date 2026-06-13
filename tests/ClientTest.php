<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransportSharing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

class ClientTest extends TestCase
{
    public function testUsesDefaultHandler(): void
    {
        $client = new Client();
        Server::enqueue([new Response(200, ['Content-Length' => '0'])]);
        $response = $client->get(Server::$url);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCanSendAsyncGetRequests(): void
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

    public function testSendAsyncRejectsWhenHandlerThrowsThrowable(): void
    {
        $previous = new \Error('handler failed');
        $client = new Client([
            'handler' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        $promise = $client->sendAsync(new Request('GET', 'http://example.com'));

        self::assertTrue(Is::rejected($promise));

        try {
            $promise->wait();
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testCanSendSynchronously(): void
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);
        $request = new Request('GET', 'http://example.com');
        $r = $client->send($request);
        self::assertInstanceOf(ResponseInterface::class, $r);
        self::assertSame(200, $r->getStatusCode());
    }

    public function testRejectsEmptyProtocolVersionRequestOption(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP protocol version must not be empty.');

        $client->get('http://example.com', ['version' => '']);
    }

    public function testSendRequestRejectsEmptyRequestProtocolVersion(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = self::requestWithProtocolVersion('');

        try {
            $client->sendRequest($request);
            self::fail('Expected request exception.');
        } catch (RequestExceptionInterface $e) {
            self::assertSame('', $e->getRequest()->getProtocolVersion());
            self::assertSame('HTTP protocol version must not be empty.', $e->getMessage());
        }

        self::assertCount(1, $mock);
    }

    /**
     * @dataProvider malformedProtocolVersionProvider
     */
    public function testRejectsMalformedProtocolVersionRequestOption(string $version): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP protocol version must be a valid HTTP version number.');

        $client->get('http://example.com', ['version' => $version]);
    }

    /**
     * @dataProvider malformedProtocolVersionProvider
     */
    public function testRejectsMalformedRequestProtocolVersion(string $version): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = self::requestWithProtocolVersion($version);

        try {
            $client->send($request);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($version, $e->getRequest()->getProtocolVersion());
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertSame('HTTP protocol version must be a valid HTTP version number.', $e->getMessage());
        }

        self::assertCount(1, $mock);
    }

    public static function malformedProtocolVersionProvider(): iterable
    {
        yield ['HTTP/1.1'];
        yield ['1.1 '];
        yield [' 1.1'];
        yield ['1.'];
        yield ['.1'];
        yield ['1.1.1'];
        yield ['foo'];
    }

    public function testClientHasOptions(): void
    {
        $client = new Client([
            'base_uri' => 'http://foo.com',
            'timeout' => 2,
            'headers' => ['bar' => 'baz'],
            'handler' => new MockHandler(),
        ]);
        $config = $client->getConfig();
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

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);

        try {
            new Client([
                'transport_sharing' => TransportSharing::HANDLER_PREFER,
            ]);

            self::assertHandlerShareWasCreated();
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testPersistentPreferTransportSharingCreatesDefaultShareHandle(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();

        $_SERVER['curl_test'] = true;
        unset(
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_init_persistent_count'],
            $_SERVER['_curl_share_persistent_options']
        );

        try {
            new Client([
                'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
            ]);

            self::assertPersistentPreferShareWasCreated();
        } finally {
            unset(
                $_SERVER['curl_test'],
                $_SERVER['_curl_share'],
                $_SERVER['_curl_share_init_count'],
                $_SERVER['_curl_share_init_persistent_count'],
                $_SERVER['_curl_share_persistent_options']
            );
        }
    }

    public function testPersistentRequireTransportSharingFailsWhenPersistentSharingIsUnavailable(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();

        if (\function_exists('curl_share_init_persistent')) {
            $_SERVER['curl_share_init_persistent_fail'] = true;
        }

        try {
            $this->expectException(\InvalidArgumentException::class);

            new Client([
                'transport_sharing' => TransportSharing::PERSISTENT_REQUIRE,
            ]);
        } finally {
            unset($_SERVER['curl_share_init_persistent_fail']);
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

    public function testPersistentPreferTransportSharingCanBeUsedWithCustomHandler(): void
    {
        $client = new Client([
            'handler' => new MockHandler(),
            'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
        ]);

        self::assertNull($client->getConfig('transport_sharing'));
    }

    /**
     * @dataProvider strictTransportSharingModeProvider
     */
    public function testRequiredTransportSharingCannotBeUsedWithCustomHandler(string $transportSharing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport_sharing');

        new Client([
            'handler' => new MockHandler(),
            'transport_sharing' => $transportSharing,
        ]);
    }

    public static function strictTransportSharingModeProvider(): iterable
    {
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
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

    public function testCanMergeOnBaseUri(): void
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

    public function testCanMergeOnBaseUriWithRequest(): void
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

    public function testCanUseRelativeUriWithSend(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://bar.com',
        ]);
        $config = $client->getConfig();
        self::assertSame('http://bar.com', (string) $config['base_uri']);
        $request = new Request('GET', '/baz');
        $client->send($request);
        self::assertSame(
            'http://bar.com/baz',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testClientHasDefaultPsr17Factories(): void
    {
        $client = new Client(['handler' => new MockHandler()]);
        $config = $client->getConfig();

        self::assertArrayHasKey(RequestOptions::REQUEST_FACTORY, $config);
        self::assertInstanceOf(RequestFactoryInterface::class, $config[RequestOptions::REQUEST_FACTORY]);
        self::assertArrayHasKey(RequestOptions::URI_FACTORY, $config);
        self::assertInstanceOf(UriFactoryInterface::class, $config[RequestOptions::URI_FACTORY]);
        self::assertArrayHasKey(RequestOptions::STREAM_FACTORY, $config);
        self::assertInstanceOf(StreamFactoryInterface::class, $config[RequestOptions::STREAM_FACTORY]);
        self::assertArrayHasKey(RequestOptions::RESPONSE_FACTORY, $config);
        self::assertInstanceOf(ResponseFactoryInterface::class, $config[RequestOptions::RESPONSE_FACTORY]);
    }

    public function testRequestUsesConfiguredRequestAndUriFactories(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->request('GET', 'http://example.com/path');

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $request);
        self::assertInstanceOf(ClientTestUri::class, $request->getUri());
        self::assertSame('http://example.com/path', (string) $request->getUri());
        self::assertSame(['http://example.com/path'], $factory->uriCalls());
        self::assertSame('GET', $factory->requestCalls()[0][0]);
        self::assertInstanceOf(ClientTestUri::class, $factory->requestCalls()[0][1]);
    }

    public function testRequestAppliesHeadersBodyQueryAndVersionAfterFactoryCreation(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::HEADERS => ['X-Test' => '1'],
            RequestOptions::BODY => 'payload',
            RequestOptions::QUERY => ['a' => 'b'],
            RequestOptions::VERSION => '2',
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $request);
        self::assertSame('1', $request->getHeaderLine('X-Test'));
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame('a=b', $request->getUri()->getQuery());
        self::assertSame('2', $request->getProtocolVersion());
    }

    public function testRequestUsesDefaultProtocolVersionWithConfiguredRequestFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new Request($method, $uri, [], null, '2.0');
            }
        };
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
        ]);

        $client->request('GET', 'http://example.com/path');

        self::assertSame('1.1', $mock->getLastRequest()->getProtocolVersion());
    }

    public function testBodyUsesConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => 'payload',
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame(['payload'], $factory->streamCalls());
    }

    public function testResourceBodyUsesConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);
        $resource = Psr7\Utils::tryFopen('php://temp', 'r+');
        \fwrite($resource, 'payload');
        \rewind($resource);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => $resource,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame(1, $factory->streamResourceCalls());
    }

    public function testStringableBodyUsesConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);
        $body = new class {
            public function __toString(): string
            {
                return 'payload';
            }
        };

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => $body,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame(['payload'], $factory->streamCalls());
    }

    public function testStringableCallableBodyUsesConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);
        $body = new class {
            /** @var bool */
            public $called = false;

            public function __toString(): string
            {
                return 'stringable';
            }

            /**
             * @return string|false
             */
            public function __invoke(int $length)
            {
                if ($this->called) {
                    return false;
                }

                $this->called = true;

                return 'callable';
            }
        };

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => $body,
        ]);

        $request = $mock->getLastRequest();
        self::assertFalse($body->called);
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('stringable', (string) $request->getBody());
        self::assertSame(['stringable'], $factory->streamCalls());
    }

    public function testStreamBodyIsPreservedWithConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $stream = Psr7\Utils::streamFor('payload');
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => $stream,
        ]);

        self::assertSame($stream, $mock->getLastRequest()->getBody());
        self::assertSame([], $factory->streamCalls());
        self::assertSame(0, $factory->streamResourceCalls());
    }

    public function testJsonUsesConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::JSON => ['foo' => 'bar'],
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('{"foo":"bar"}', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['{"foo":"bar"}'], $factory->streamCalls());
    }

    public function testFormParamsUseConfiguredStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::FORM_PARAMS => ['foo' => 'bar baz'],
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('foo=bar+baz', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(['foo=bar+baz'], $factory->streamCalls());
    }

    public function testSendUsesConfiguredStreamFactoryForBodyOption(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->send(new Request('POST', 'http://example.com/path'), [
            RequestOptions::BODY => 'payload',
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(Request::class, $request);
        self::assertInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame([], $factory->requestCalls());
        self::assertSame([], $factory->uriCalls());
        self::assertSame(['payload'], $factory->streamCalls());
    }

    public function testRequestPreservesMethodCasingWithConfiguredRequestFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
        ]);

        $client->request('gEt', 'http://example.com/path');

        self::assertSame('gEt', $factory->requestCalls()[0][0]);
        self::assertSame('gEt', $mock->getLastRequest()->getMethod());
    }

    public function testStringBaseUriUsesConfiguredUriFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://example.com/base/',
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $config = $client->getConfig();
        self::assertInstanceOf(ClientTestUri::class, $config['base_uri']);

        $client->request('GET', 'relative');

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestUri::class, $request->getUri());
        self::assertSame('http://example.com/base/relative', (string) $request->getUri());
        self::assertSame(
            ['http://example.com/base/', 'relative', 'http://example.com/base/relative'],
            $factory->uriCalls()
        );
    }

    public function testPerRequestFactoriesOverrideClientFactories(): void
    {
        $mock = new MockHandler([new Response()]);
        $clientFactory = new ClientTestFactory();
        $requestFactory = new ClientTestFactory(ClientTestAlternateRequest::class, ClientTestAlternateUri::class);
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $clientFactory,
            RequestOptions::URI_FACTORY => $clientFactory,
        ]);

        $client->request('GET', 'http://example.com/path', [
            RequestOptions::REQUEST_FACTORY => $requestFactory,
            RequestOptions::URI_FACTORY => $requestFactory,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestAlternateRequest::class, $request);
        self::assertInstanceOf(ClientTestAlternateUri::class, $request->getUri());
        self::assertSame([], $clientFactory->requestCalls());
        self::assertSame([], $clientFactory->uriCalls());
    }

    public function testPerRequestBaseUriUsesPerRequestUriFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $clientFactory = new ClientTestFactory();
        $requestFactory = new ClientTestFactory(ClientTestAlternateRequest::class, ClientTestAlternateUri::class);
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://client.example/base/',
            RequestOptions::REQUEST_FACTORY => $clientFactory,
            RequestOptions::URI_FACTORY => $clientFactory,
        ]);

        $client->request('GET', 'relative', [
            'base_uri' => 'http://request.example/base/',
            RequestOptions::REQUEST_FACTORY => $requestFactory,
            RequestOptions::URI_FACTORY => $requestFactory,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestAlternateUri::class, $request->getUri());
        self::assertSame('http://request.example/base/relative', (string) $request->getUri());
        self::assertSame(['http://client.example/base/'], $clientFactory->uriCalls());
        self::assertSame(
            ['relative', 'http://request.example/base/', 'http://request.example/base/relative'],
            $requestFactory->uriCalls()
        );
    }

    public function testPerRequestUriFactoryControlsResolvedUriWithClientBaseUriString(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://example.com/base/',
        ]);

        $client->request('GET', 'relative', [
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $request);
        self::assertInstanceOf(ClientTestUri::class, $request->getUri());
        self::assertSame('http://example.com/base/relative', (string) $request->getUri());
        self::assertSame(['relative', 'http://example.com/base/relative'], $factory->uriCalls());
    }

    public function testNullPerRequestRequestFactoryFallsBackToDefaultFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->request('GET', 'http://example.com/path', [
            RequestOptions::REQUEST_FACTORY => null,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(Request::class, $request);
        self::assertNotInstanceOf(ClientTestRequest::class, $request);
        self::assertInstanceOf(ClientTestUri::class, $request->getUri());
        self::assertSame([], $factory->requestCalls());
    }

    public function testNullPerRequestUriFactoryFallsBackToDefaultFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->request('GET', 'http://example.com/path', [
            RequestOptions::URI_FACTORY => null,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $request);
        self::assertInstanceOf(Uri::class, $request->getUri());
        self::assertNotInstanceOf(ClientTestUri::class, $request->getUri());
        self::assertSame([], $factory->uriCalls());
    }

    public function testNullPerRequestUriFactoryFallsBackToDefaultFactoryWhenResolvingClientBaseUri(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://example.com/base/',
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->request('GET', 'relative', [
            RequestOptions::URI_FACTORY => null,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(ClientTestRequest::class, $request);
        self::assertInstanceOf(Uri::class, $request->getUri());
        self::assertNotInstanceOf(ClientTestUri::class, $request->getUri());
        self::assertSame('http://example.com/base/relative', (string) $request->getUri());
        self::assertSame(['http://example.com/base/'], $factory->uriCalls());
    }

    public function testPerRequestStreamFactoryOverridesClientStreamFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $clientFactory = new ClientTestFactory();
        $requestFactory = new ClientTestFactory(ClientTestRequest::class, ClientTestUri::class, ClientTestAlternateStream::class);
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $clientFactory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => 'payload',
            RequestOptions::STREAM_FACTORY => $requestFactory,
        ]);

        self::assertSame([], $clientFactory->streamCalls());
        self::assertSame(['payload'], $requestFactory->streamCalls());
        self::assertInstanceOf(ClientTestAlternateStream::class, $mock->getLastRequest()->getBody());
    }

    public function testNullPerRequestStreamFactoryFallsBackToDefaultFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => 'payload',
            RequestOptions::STREAM_FACTORY => null,
        ]);

        $request = $mock->getLastRequest();
        self::assertNotInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame([], $factory->streamCalls());
    }

    public function testResponseFactoryIsForwardedToHandler(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::RESPONSE_FACTORY => $factory,
        ]);

        $client->request('GET', 'http://example.com/path');

        self::assertSame($factory, $mock->getLastOptions()[RequestOptions::RESPONSE_FACTORY]);
    }

    public function testPerRequestResponseFactoryOverridesClientResponseFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $clientFactory = new ClientTestFactory();
        $requestFactory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::RESPONSE_FACTORY => $clientFactory,
        ]);

        $client->request('GET', 'http://example.com/path', [
            RequestOptions::RESPONSE_FACTORY => $requestFactory,
        ]);

        self::assertSame($requestFactory, $mock->getLastOptions()[RequestOptions::RESPONSE_FACTORY]);
    }

    public function testNullPerRequestResponseFactoryRemovesClientDefaultBeforeHandler(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::RESPONSE_FACTORY => $factory,
        ]);

        $client->request('GET', 'http://example.com/path', [
            RequestOptions::RESPONSE_FACTORY => null,
        ]);

        self::assertArrayNotHasKey(RequestOptions::RESPONSE_FACTORY, $mock->getLastOptions());
    }

    public function testCallableBodyFallsBackToGuzzleStreamHandling(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $called = false;
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => static function (int $length) use (&$called) {
                if ($called) {
                    return false;
                }

                $called = true;

                return 'payload';
            },
        ]);

        $request = $mock->getLastRequest();
        self::assertNotInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame([], $factory->streamCalls());
    }

    public function testIteratorBodyFallsBackToGuzzleStreamHandling(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::BODY => new \ArrayIterator(['pay', 'load']),
        ]);

        $request = $mock->getLastRequest();
        self::assertNotInstanceOf(ClientTestStream::class, $request->getBody());
        self::assertSame('payload', (string) $request->getBody());
        self::assertSame([], $factory->streamCalls());
    }

    public function testMultipartBodyDoesNotUseConfiguredStreamFactoryForAggregateBody(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::STREAM_FACTORY => $factory,
        ]);

        $client->request('POST', 'http://example.com/path', [
            RequestOptions::MULTIPART => [
                [
                    'name' => 'foo',
                    'contents' => 'bar',
                ],
            ],
        ]);

        self::assertInstanceOf(Psr7\MultipartStream::class, $mock->getLastRequest()->getBody());
        self::assertSame([], $factory->streamCalls());
    }

    /**
     * @dataProvider arrayBodyWithDerivedBodyOptionsProvider
     *
     * @param array<string, mixed> $options
     */
    public function testArrayBodyIsRejectedBeforeDerivedBodyOptions(array $options): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);
        $options[RequestOptions::BODY] = ['invalid'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing in the "body" request option as an array');

        $client->request('POST', 'http://example.com/path', $options);
    }

    public static function arrayBodyWithDerivedBodyOptionsProvider(): array
    {
        return [
            'json' => [
                [RequestOptions::JSON => ['foo' => 'bar']],
            ],
            'form_params' => [
                [RequestOptions::FORM_PARAMS => ['foo' => 'bar']],
            ],
            'multipart' => [
                [RequestOptions::MULTIPART => [
                    [
                        'name' => 'foo',
                        'contents' => 'bar',
                    ],
                ]],
            ],
        ];
    }

    public function testInvalidRequestFactoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('request_factory must be an instance of Psr\\Http\\Message\\RequestFactoryInterface');

        new Client([
            RequestOptions::REQUEST_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidUriFactoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('uri_factory must be an instance of Psr\\Http\\Message\\UriFactoryInterface');

        new Client([
            RequestOptions::URI_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidStreamFactoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_factory must be an instance of Psr\\Http\\Message\\StreamFactoryInterface');

        new Client([
            RequestOptions::STREAM_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidResponseFactoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('response_factory must be an instance of Psr\\Http\\Message\\ResponseFactoryInterface');

        new Client([
            RequestOptions::RESPONSE_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidPerRequestRequestFactoryIsRejected(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('request_factory must be an instance of Psr\\Http\\Message\\RequestFactoryInterface');

        $client->request('GET', 'http://example.com', [
            RequestOptions::REQUEST_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidPerRequestUriFactoryIsRejected(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('uri_factory must be an instance of Psr\\Http\\Message\\UriFactoryInterface');

        $client->request('GET', 'http://example.com', [
            RequestOptions::URI_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidPerRequestStreamFactoryIsRejected(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_factory must be an instance of Psr\\Http\\Message\\StreamFactoryInterface');

        $client->request('POST', 'http://example.com', [
            RequestOptions::BODY => 'payload',
            RequestOptions::STREAM_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidPerRequestResponseFactoryIsRejected(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('response_factory must be an instance of Psr\\Http\\Message\\ResponseFactoryInterface');

        // A body-less GET must still reject an invalid per-request response
        // factory: response factory validation is unconditional because every
        // request yields a response.
        $client->request('GET', 'http://example.com', [
            RequestOptions::RESPONSE_FACTORY => new \stdClass(),
        ]);
    }

    public function testInvalidPerRequestStreamFactoryIsRejectedForBodylessRequest(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_factory must be an instance of Psr\\Http\\Message\\StreamFactoryInterface');

        // A body-less GET must still reject an invalid per-request stream
        // factory: the built-in handlers use it for the response body stream,
        // so its validation is unconditional rather than body-gated.
        $client->request('GET', 'http://example.com', [
            RequestOptions::STREAM_FACTORY => new \stdClass(),
        ]);
    }

    public function testSendRejectsInvalidPerRequestStreamFactory(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_factory must be an instance of Psr\\Http\\Message\\StreamFactoryInterface');

        $client->send(new Request('GET', 'http://example.com'), [
            RequestOptions::STREAM_FACTORY => new \stdClass(),
        ]);
    }

    public function testSendRejectsInvalidPerRequestResponseFactory(): void
    {
        $client = new Client([
            'handler' => new MockHandler([new Response()]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('response_factory must be an instance of Psr\\Http\\Message\\ResponseFactoryInterface');

        $client->send(new Request('GET', 'http://example.com'), [
            RequestOptions::RESPONSE_FACTORY => new \stdClass(),
        ]);
    }

    public function testSendDoesNotUseRequestFactory(): void
    {
        $mock = new MockHandler([new Response()]);
        $factory = new ClientTestFactory();
        $client = new Client([
            'handler' => $mock,
            RequestOptions::REQUEST_FACTORY => $factory,
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->send(new Request('GET', 'http://example.com/path'));

        self::assertInstanceOf(Request::class, $mock->getLastRequest());
        self::assertNotInstanceOf(ClientTestRequest::class, $mock->getLastRequest());
        self::assertSame([], $factory->requestCalls());
        self::assertSame([], $factory->uriCalls());
    }

    public function testMergesDefaultOptionsAndDoesNotOverwriteUa(): void
    {
        $client = new Client(['headers' => ['User-agent' => 'foo']]);
        $config = $client->getConfig();
        self::assertSame(['User-agent' => 'foo'], $config['headers']);
        self::assertIsArray($config['allow_redirects']);
        self::assertTrue($config['http_errors']);
        self::assertTrue($config['decode_content']);
        self::assertTrue($config['verify']);
    }

    public function testDoesNotOverwriteHeaderWithDefault(): void
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
            || !CurlVersion::supportsHandlerSharing()
        ) {
            self::markTestSkipped('Default cURL handler with share handles is unavailable.');
        }
    }

    private static function assertPersistentPreferShareWasCreated(): void
    {
        if (
            CurlVersion::supportsConnectionSharing()
            && CurlVersion::supportsSslSessionSharing()
            && \function_exists('curl_share_init_persistent')
            && \class_exists('CurlSharePersistentHandle')
            && \defined('CURL_LOCK_DATA_DNS')
            && \defined('CURL_LOCK_DATA_CONNECT')
            && \defined('CURL_LOCK_DATA_SSL_SESSION')
        ) {
            self::assertSame(1, $_SERVER['_curl_share_init_persistent_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_CONNECT,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share_persistent_options']);

            return;
        }

        self::assertHandlerShareWasCreated();
    }

    private static function assertHandlerShareWasCreated(): void
    {
        $locks = [\CURL_LOCK_DATA_DNS];
        if (CurlVersion::supportsSslSessionSharing()) {
            $locks[] = \CURL_LOCK_DATA_SSL_SESSION;
        }

        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame($locks, $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }

    public function testDoesNotOverwriteHeaderWithDefaultInRequest(): void
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

    public function testDoesOverwriteHeaderWithSetRequestOption(): void
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

    public function testCanUnsetRequestOptionWithNull(): void
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['foo' => 'bar'],
            'handler' => $mock,
        ]);
        $c->get('http://example.com', ['headers' => null]);
        self::assertFalse($mock->getLastRequest()->hasHeader('foo'));
    }

    public function testAllowRedirectsCanBeTrue(): void
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://foo.com', ['allow_redirects' => true]);
        self::assertIsArray($mock->getLastOptions()['allow_redirects']);
    }

    public function testValidatesAllowRedirects(): void
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing string to request option "allow_redirects" is invalid; expected bool|array.');
        $client->get('http://foo.com', ['allow_redirects' => 'foo']);
    }

    public function testThrowsHttpErrorsByDefault(): void
    {
        $mock = new MockHandler([new Response(404)]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        $client->get('http://foo.com');
    }

    public function testValidatesCookies(): void
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing string to request option "cookies" is invalid; expected false|CookieJarInterface.');
        $client->get('http://foo.com', ['cookies' => 'foo']);
    }

    public function testSetCookieToTrueUsesSharedJar(): void
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

    public function testSetCookieToJar(): void
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

    public function testCanDisableContentDecoding(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => false]);
        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Accept-Encoding'));
        self::assertFalse($mock->getLastOptions()['decode_content']);
    }

    public function testCanSetContentDecodingToValue(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => 'gzip']);
        $last = $mock->getLastRequest();
        self::assertSame('gzip', $last->getHeaderLine('Accept-Encoding'));
        self::assertSame('gzip', $mock->getLastOptions()['decode_content']);
    }

    public function testAddsAcceptEncodingbyCurl(): void
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

    public function testValidatesHeaders(): void
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('http://foo.com', ['headers' => 'foo']);
    }

    /**
     * @dataProvider invalidRequestOptionTypeProvider
     */
    public function testRejectsInvalidRequestOptionTypes(array $options, string $expectedMessage): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $client->request('POST', 'http://foo.com', $options);
    }

    public static function invalidRequestOptionTypeProvider(): iterable
    {
        yield 'allow_redirects' => [
            ['allow_redirects' => 'true'],
            'Passing string to request option "allow_redirects" is invalid; expected bool|array.',
        ];

        yield 'allow_redirects.protocols' => [
            ['allow_redirects' => ['protocols' => []]],
            'Passing array to request option "allow_redirects.protocols" is invalid; expected non-empty-array<array-key, string>.',
        ];

        yield 'allow_redirects.protocols value' => [
            ['allow_redirects' => ['protocols' => [false]]],
            'Passing bool to request option "allow_redirects.protocols.0" is invalid; expected string.',
        ];

        yield 'auth' => [
            ['auth' => true],
            'Passing bool to request option "auth" is invalid; expected array{0: string, 1: string, 2?: string|null}|string|false|null.',
        ];

        yield 'body' => [
            ['body' => new \stdClass()],
            'Passing stdClass to request option "body" is invalid; expected resource|string|null|StreamInterface|callable&object|Iterator|Stringable.',
        ];

        yield 'body int' => [
            ['body' => 1],
            'Passing int to request option "body" is invalid; expected resource|string|null|StreamInterface|callable&object|Iterator|Stringable.',
        ];

        yield 'cert password' => [
            ['cert' => ['cert.pem', new \stdClass()]],
            'Passing stdClass to request option "cert.1" is invalid; expected string|null.',
        ];

        yield 'cert_type' => [
            ['cert_type' => false],
            'Passing bool to request option "cert_type" is invalid; expected string.',
        ];

        yield 'connect_timeout' => [
            ['connect_timeout' => '1'],
            'Passing string to request option "connect_timeout" is invalid; expected int|float.',
        ];

        yield 'crypto_method' => [
            ['crypto_method' => '1'],
            'Passing string to request option "crypto_method" is invalid; expected int.',
        ];

        yield 'debug' => [
            ['debug' => 'debug'],
            'Passing string to request option "debug" is invalid; expected bool|resource.',
        ];

        yield 'decode_content' => [
            ['decode_content' => 1],
            'Passing int to request option "decode_content" is invalid; expected bool|string.',
        ];

        yield 'delay' => [
            ['delay' => '1'],
            'Passing string to request option "delay" is invalid; expected int|float.',
        ];

        yield 'expect' => [
            ['expect' => 1.5],
            'Passing float to request option "expect" is invalid; expected bool|int.',
        ];

        yield 'form param value' => [
            ['form_params' => ['foo' => new \stdClass()]],
            'Passing stdClass to request option "form_params.foo" is invalid; expected string|int|float|bool|null|array.',
        ];

        yield 'force_ip_resolve' => [
            ['force_ip_resolve' => false],
            'Passing bool to request option "force_ip_resolve" is invalid; expected string.',
        ];

        yield 'header value' => [
            ['headers' => ['X-Test' => []]],
            'Passing array to request option "headers.X-Test" is invalid; expected string|non-empty-array<array-key, string>.',
        ];

        yield 'multipart contents' => [
            ['multipart' => [['name' => 'foo']]],
            'Passing array to request option "multipart.0" is invalid; expected array{name: string|int, contents: mixed, headers?: array<array-key, string>, filename?: string}.',
        ];

        yield 'multipart header value' => [
            ['multipart' => [['name' => 'foo', 'contents' => 'bar', 'headers' => ['X-Test' => false]]]],
            'Passing bool to request option "multipart.0.headers.X-Test" is invalid; expected string.',
        ];

        yield 'http_errors' => [
            ['http_errors' => 'false'],
            'Passing string to request option "http_errors" is invalid; expected bool.',
        ];

        yield 'on_headers' => [
            ['on_headers' => 'not a callable'],
            'Passing string to request option "on_headers" is invalid; expected callable.',
        ];

        yield 'on_stats' => [
            ['on_stats' => 'not a callable'],
            'Passing string to request option "on_stats" is invalid; expected callable.',
        ];

        yield 'progress' => [
            ['progress' => 'not a callable'],
            'Passing string to request option "progress" is invalid; expected callable.',
        ];

        yield 'protocols' => [
            ['protocols' => []],
            'Passing array to request option "protocols" is invalid; expected non-empty-array<array-key, string>.',
        ];

        yield 'protocol value' => [
            ['protocols' => [false]],
            'Passing bool to request option "protocols.0" is invalid; expected string.',
        ];

        yield 'proxy no value' => [
            ['proxy' => ['no' => [false]]],
            'Passing bool to request option "proxy.no.0" is invalid; expected string.',
        ];

        yield 'retries' => [
            ['retries' => '1'],
            'Passing string to request option "retries" is invalid; expected int.',
        ];

        yield 'sink' => [
            ['sink' => 123],
            'Passing int to request option "sink" is invalid; expected resource|string|StreamInterface.',
        ];

        yield 'ssl_key password' => [
            ['ssl_key' => ['key.pem', new \stdClass()]],
            'Passing stdClass to request option "ssl_key.1" is invalid; expected string|null.',
        ];

        yield 'ssl_key_type' => [
            ['ssl_key_type' => false],
            'Passing bool to request option "ssl_key_type" is invalid; expected string.',
        ];

        yield 'stream' => [
            ['stream' => '1'],
            'Passing string to request option "stream" is invalid; expected bool.',
        ];

        yield 'stream_context' => [
            ['stream_context' => 'context'],
            'Passing string to request option "stream_context" is invalid; expected array<array-key, mixed>.',
        ];

        yield 'timeout' => [
            ['timeout' => '1'],
            'Passing string to request option "timeout" is invalid; expected int|float.',
        ];

        yield 'verify' => [
            ['verify' => 1],
            'Passing int to request option "verify" is invalid; expected bool|string.',
        ];

        yield 'version' => [
            ['version' => true],
            'Passing bool to request option "version" is invalid; expected string|int|float.',
        ];

        yield 'curl' => [
            ['curl' => 'curl'],
            'Passing string to request option "curl" is invalid; expected array<int|string, mixed>.',
        ];
    }

    public function testRejectsInvalidSynchronousRequestOption(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing string to request option "synchronous" is invalid; expected bool.');

        $client->sendAsync(new Request('GET', 'http://foo.com'), ['synchronous' => '1']);
    }

    public function testAddsBody(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['body' => 'foo']);
        $last = $mock->getLastRequest();
        self::assertSame('foo', (string) $last->getBody());
    }

    public function testAddsIteratorBody(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, [
            'body' => new \ArrayIterator(['foo', 'bar']),
        ]);
        $last = $mock->getLastRequest();
        self::assertSame('foobar', (string) $last->getBody());
    }

    public function testValidatesQuery(): void
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');

        $this->expectException(\InvalidArgumentException::class);
        $client->send($request, ['query' => false]);
    }

    public function testQueryCanBeString(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => 'foo']);
        self::assertSame('foo', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testQueryCanBeArray(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => ['foo' => 'bar baz']]);
        self::assertSame('foo=bar%20baz', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testCanAddJsonData(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['json' => ['foo' => 'bar']]);
        $last = $mock->getLastRequest();
        self::assertSame('{"foo":"bar"}', (string) $mock->getLastRequest()->getBody());
        self::assertSame('application/json', $last->getHeaderLine('Content-Type'));
        self::assertFalse($last->hasHeader('Accept'));
    }

    public function testCanAddJsonDataWithAcceptHeader(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, [
            'headers' => ['Accept' => 'application/vnd.api+json'],
            'json' => ['foo' => 'bar'],
        ]);
        $last = $mock->getLastRequest();
        self::assertSame('{"foo":"bar"}', (string) $last->getBody());
        self::assertSame('application/json', $last->getHeaderLine('Content-Type'));
        self::assertSame('application/vnd.api+json', $last->getHeaderLine('Accept'));
    }

    public function testCanAddJsonDataWithoutOverwritingContentType(): void
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

    public function testCanAddJsonDataWithNullHeader(): void
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

    public function testAuthCanBeDisabledWithNull(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => null]);

        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeNull(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock, 'auth' => ['a', 'b']]);
        $client->get('http://foo.com', ['auth' => null]);

        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeDisabledWithFalse(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => false]);

        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Authorization'));
    }

    public function testEmptyAuthArrayIsIgnored(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => []]);

        $last = $mock->getLastRequest();
        self::assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeCustomStringForHandlers(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => 'custom']);

        self::assertSame('custom', $mock->getLastOptions()['auth']);
    }

    public function testAuthCanBeArrayForBasicAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $client->get('http://foo.com', ['auth' => ['a', 'b']]);
        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanBeArrayForExplicitBasicAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'basic']]);

        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanUseNullTypeForDefaultBasicAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', null]]);

        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanBeArrayForDigestAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'digest']]);
        $last = $mock->getLastOptions();
        self::assertSame(['a', 'b', 'digest'], $last['auth']);
        self::assertArrayNotHasKey('curl', $last);
    }

    public function testUnknownArrayAuthTypePassesThroughForCustomMiddleware(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'custom']]);

        self::assertSame(['a', 'b', 'custom'], $mock->getLastOptions()['auth']);
        self::assertFalse($mock->getLastRequest()->hasHeader('Authorization'));
    }

    public function testLegacyNtlmAuthTypePassesThroughForCustomMiddleware(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'ntlm']]);

        self::assertSame(['a', 'b', 'ntlm'], $mock->getLastOptions()['auth']);
        self::assertFalse($mock->getLastRequest()->hasHeader('Authorization'));
        self::assertArrayNotHasKey('curl', $mock->getLastOptions());
    }

    /**
     * @dataProvider invalidAuthOptionProvider
     *
     * @param mixed[] $auth
     */
    public function testValidatesAuthOptionArray(array $auth): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);

        $this->expectException(InvalidArgumentException::class);
        $client->get('http://foo.com', ['auth' => $auth]);
    }

    public static function invalidAuthOptionProvider(): array
    {
        return [
            [['user']],
            [[['user'], 'pass']],
            [['user', ['pass']]],
            [['user', 'pass', 1]],
            [['user', 'pass', []]],
        ];
    }

    public function testCanAddFormParams(): void
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

    public function testFormParamsAcceptScalarAndNullValues(): void
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

    /**
     * @dataProvider nonFiniteFloatProvider
     */
    public function testFormParamsRejectNonFiniteFloats(float $value): void
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing a non-finite float to request option "form_params.score" is invalid; non-finite floats are not supported.');
        $client->post('http://foo.com', ['form_params' => ['score' => $value]]);
    }

    /**
     * @dataProvider nonFiniteFloatProvider
     */
    public function testQueryRejectsNonFiniteFloats(float $value): void
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing a non-finite float to request option "query.score" is invalid; non-finite floats are not supported.');
        $client->get('http://foo.com', ['query' => ['score' => $value]]);
    }

    public static function nonFiniteFloatProvider(): array
    {
        return [
            'NAN' => [\NAN],
            'INF' => [\INF],
            '-INF' => [-\INF],
        ];
    }

    public function testTlsPassphraseOptionsAcceptNullPasswordSlot(): void
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

    public function testFormParamsEncodedProperly(): void
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

    public function testEnsuresThatFormParamsAndMultipartAreExclusive(): void
    {
        $client = new Client(['handler' => static function (): void {
        }]);

        $this->expectException(\InvalidArgumentException::class);
        $client->post('http://foo.com', [
            'form_params' => ['foo' => 'bar bam'],
            'multipart' => [],
        ]);
    }

    public function testCanSendMultipart(): void
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

    public function testCanSendMultipartWithExplicitBody(): void
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

    /**
     * @dataProvider multipartBoundaryRequiringQuotesProvider
     */
    public function testQuotesMultipartBoundaryParameterWhenRequired(string $boundary): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request(
            'POST',
            'http://foo.com',
            [],
            new Psr7\MultipartStream([], $boundary)
        ));

        $last = $mock->getLastRequest();
        self::assertSame(
            'multipart/form-data; boundary="'.$boundary.'"',
            $last->getHeaderLine('Content-Type')
        );
    }

    public static function multipartBoundaryRequiringQuotesProvider(): iterable
    {
        yield 'colon' => ['abc:def'];
        yield 'slash' => ['abc/def'];
        yield 'parentheses' => ['abc(def)'];
        yield 'space' => ['abc def'];
        yield 'question mark' => ['abc?def'];
        yield 'equals' => ['abc=def'];
        yield 'comma' => ['abc,def'];
    }

    /**
     * @dataProvider unquotedMultipartBoundaryProvider
     */
    public function testLeavesMultipartBoundaryParameterUnquotedWhenPossible(string $boundary): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request(
            'POST',
            'http://foo.com',
            [],
            new Psr7\MultipartStream([], $boundary)
        ));

        $last = $mock->getLastRequest();
        self::assertSame(
            'multipart/form-data; boundary='.$boundary,
            $last->getHeaderLine('Content-Type')
        );
    }

    public static function unquotedMultipartBoundaryProvider(): iterable
    {
        yield 'letters and digits' => ['abc123'];
        yield 'hyphen and underscore' => ['abc-def_123'];
        yield 'apostrophe' => ["abc'def"];
        yield 'plus' => ['abc+def'];
        yield 'period' => ['abc.def'];
    }

    public function testPreservesExistingMultipartContentTypeHeader(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request(
            'POST',
            'http://foo.com',
            ['Content-Type' => 'multipart/form-data; boundary=provided'],
            new Psr7\MultipartStream([], 'abc:def')
        ));

        $last = $mock->getLastRequest();
        self::assertSame(
            'multipart/form-data; boundary=provided',
            $last->getHeaderLine('Content-Type')
        );
    }

    public function testUsesProxyEnvironmentVariables(): void
    {
        unset($_SERVER['HTTP_PROXY'], $_SERVER['HTTPS_PROXY'], $_SERVER['NO_PROXY']);
        \putenv('HTTP_PROXY=');
        \putenv('HTTPS_PROXY=');
        \putenv('NO_PROXY=');

        try {
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayNotHasKey('proxy', $config);

            \putenv('HTTP_PROXY=127.0.0.1');
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['http' => '127.0.0.1'], $config['proxy']);

            \putenv('HTTPS_PROXY=127.0.0.2');
            \putenv('NO_PROXY= 127.0.0.3 , 127.0.0.4 , [::1]:8080 ');
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(
                ['http' => '127.0.0.1', 'https' => '127.0.0.2', 'no' => ['127.0.0.3', '127.0.0.4', '[::1]:8080']],
                $config['proxy']
            );

            \putenv('HTTP_PROXY=');
            \putenv('HTTPS_PROXY=');
            \putenv('NO_PROXY=0');
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['no' => ['0']], $config['proxy']);

            \putenv('NO_PROXY= , , ');
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayNotHasKey('proxy', $config);

            \putenv('HTTP_PROXY=127.0.0.1');
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['http' => '127.0.0.1'], $config['proxy']);

            \putenv('HTTP_PROXY=');

            \putenv('NO_PROXY=exa mple.com, foo.com');
            $client = new Client();
            $config = $client->getConfig();
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['no' => ['exa', 'mple.com', 'foo.com']], $config['proxy']);
        } finally {
            \putenv('HTTP_PROXY=');
            \putenv('HTTPS_PROXY=');
            \putenv('NO_PROXY=');
        }
    }

    public function testNullProxyValuesAreAccepted(): void
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

    public function testRequestSendsWithSync(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->request('GET', 'http://foo.com');
        self::assertTrue($mock->getLastOptions()['synchronous']);
    }

    public function testSendSendsWithSync(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request('GET', 'http://foo.com'));
        self::assertTrue($mock->getLastOptions()['synchronous']);
    }

    public function testSendWithInvalidHeader(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');

        $this->expectException(InvalidArgumentException::class);
        $client->send($request, ['headers' => ['X-Foo: Bar']]);
    }

    public function testSendWithInvalidHeaders(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');

        $this->expectException(InvalidArgumentException::class);
        $client->send($request, ['headers' => ['X-Foo: Bar', 'X-Test: Fail']]);
    }

    public function testDefaultHeadersHandleNumericHeaderNames(): void
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

    public function testRequestHeadersHandleNumericHeaderNames(): void
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

    public function testProperlyBuildsQuery(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => ['foo' => 'bar', 'john' => 'doe']]);
        self::assertSame('foo=bar&john=doe', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testSendSendsWithIpAddressAndPortAndHostHeaderInRequestTheHostShouldBePreserved(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['base_uri' => 'http://127.0.0.1:8585', 'handler' => $mockHandler]);
        $request = new Request('GET', '/test', ['Host' => 'foo.com']);

        $client->send($request);

        self::assertSame('foo.com', $mockHandler->getLastRequest()->getHeader('Host')[0]);
    }

    public function testSendSendsWithDomainAndHostHeaderInRequestTheHostShouldBePreserved(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['base_uri' => 'http://foo2.com', 'handler' => $mockHandler]);
        $request = new Request('GET', '/test', ['Host' => 'foo.com']);

        $client->send($request);

        self::assertSame('foo.com', $mockHandler->getLastRequest()->getHeader('Host')[0]);
    }

    public function testValidatesSink(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('http://test.com', ['sink' => true]);
    }

    public function testHttpDefaultSchemeIfUriHasNone(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', '//example.org/test');

        self::assertSame('http://example.org/test', (string) $mockHandler->getLastRequest()->getUri());
    }

    public function testOnlyAddSchemeWhenHostIsPresent(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', 'baz');
        self::assertSame(
            'baz',
            (string) $mockHandler->getLastRequest()->getUri()
        );
    }

    /**
     * @dataProvider versionProvider
     *
     * @param float|int|string $version
     */
    public function testNormalizesVersionOption($version, string $expected): void
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
        yield ['3', '3'];
        yield [3.0, '3.0'];
    }

    public function testSendPreservesCustomRequestWhenApplyingRequestOptions(): void
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

    public function testSendPreservesCustomUriWhenMergingBaseUri(): void
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

    public function testHandlerIsCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(['handler' => 'not_cllable']);
    }

    public function testResponseBodyAsString(): void
    {
        $responseBody = '{ "package": "guzzle" }';
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $responseBody)]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');
        $response = $client->send($request, ['json' => ['a' => 'b']]);

        self::assertSame($responseBody, (string) $response->getBody());
    }

    public function testResponseContent(): void
    {
        $responseBody = '{ "package": "guzzle" }';
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $responseBody)]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('POST', 'http://foo.com');
        $response = $client->send($request, ['json' => ['a' => 'b']]);

        self::assertSame($responseBody, $response->getBody()->getContents());
    }

    public function testIdnSupportDefaultValue(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $config = $client->getConfig();

        self::assertFalse($config['idn_conversion']);
    }

    /**
     * @requires extension idn
     */
    public function testIdnIsTranslatedToAsciiWhenConversionIsEnabled(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', 'https://яндекс.рф/images', ['idn_conversion' => true]);

        $request = $mockHandler->getLastRequest();

        self::assertSame('https://xn--d1acpjx3f.xn--p1ai/images', (string) $request->getUri());
        self::assertSame('xn--d1acpjx3f.xn--p1ai', (string) $request->getHeaderLine('Host'));
    }

    public function testIdnStaysTheSameWhenConversionIsDisabled(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $client->request('GET', 'https://яндекс.рф/images', ['idn_conversion' => false]);

        $request = $mockHandler->getLastRequest();

        self::assertSame('https://яндекс.рф/images', (string) $request->getUri());
        self::assertSame('яндекс.рф', (string) $request->getHeaderLine('Host'));
    }

    public function testIdnConversionRejectsInvalidValue(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idn_conversion must be true, false, null, or an integer IDNA_* bitmask');

        $client->request('GET', 'https://example.com', ['idn_conversion' => '0']);
    }

    /**
     * @requires extension idn
     */
    public function testExceptionOnInvalidIdn(): void
    {
        $mockHandler = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mockHandler]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IDN conversion failed');
        $client->request('GET', 'https://-яндекс.рф/images', ['idn_conversion' => true]);
    }

    /**
     * @depends testCanUseRelativeUriWithSend
     *
     * @requires extension idn
     */
    public function testIdnBaseUri(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler' => $mock,
            'base_uri' => 'http://яндекс.рф',
            'idn_conversion' => true,
        ]);
        $config = $client->getConfig();
        self::assertSame('http://яндекс.рф', (string) $config['base_uri']);
        $request = new Request('GET', '/baz');
        $client->send($request);
        self::assertSame('http://xn--d1acpjx3f.xn--p1ai/baz', (string) $mock->getLastRequest()->getUri());
        self::assertSame('xn--d1acpjx3f.xn--p1ai', (string) $mock->getLastRequest()->getHeaderLine('Host'));
    }

    /**
     * @requires extension idn
     */
    public function testIdnWithRedirect(): void
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

    private static function requestWithProtocolVersion(string $protocolVersion): RequestInterface
    {
        return new ClientTestRequestWithProtocolVersion($protocolVersion);
    }
}

final class ClientTestRequestWithProtocolVersion extends Request
{
    /** @var string */
    private $protocolVersion;

    public function __construct(string $protocolVersion)
    {
        parent::__construct('GET', 'http://example.com');

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
}

final class ClientTestRequest extends Request
{
}

final class ClientTestUri extends Uri
{
}

final class ClientTestAlternateRequest extends Request
{
}

final class ClientTestAlternateUri extends Uri
{
}

final class ClientTestStream extends Psr7\Stream
{
}

final class ClientTestAlternateStream extends Psr7\Stream
{
}

final class ClientTestResponse extends Response
{
}

final class ClientTestFactory implements RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface, UriFactoryInterface
{
    /** @var class-string<Request> */
    private $requestClass;

    /** @var class-string<Uri> */
    private $uriClass;

    /** @var class-string<Psr7\Stream> */
    private $streamClass;

    /** @var class-string<Response> */
    private $responseClass;

    /** @var array<int, array{0: string, 1: mixed}> */
    private $requestCalls = [];

    /** @var string[] */
    private $uriCalls = [];

    /** @var string[] */
    private $streamCalls = [];

    /** @var int */
    private $streamResourceCalls = 0;

    /** @var array<int, array{0: int, 1: string}> */
    private $responseCalls = [];

    /**
     * @param class-string<Request>     $requestClass
     * @param class-string<Uri>         $uriClass
     * @param class-string<Psr7\Stream> $streamClass
     * @param class-string<Response>    $responseClass
     */
    public function __construct(
        string $requestClass = ClientTestRequest::class,
        string $uriClass = ClientTestUri::class,
        string $streamClass = ClientTestStream::class,
        string $responseClass = ClientTestResponse::class
    ) {
        $this->requestClass = $requestClass;
        $this->uriClass = $uriClass;
        $this->streamClass = $streamClass;
        $this->responseClass = $responseClass;
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        $this->requestCalls[] = [$method, $uri];

        $class = $this->requestClass;

        return new $class($method, $uri);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        $this->uriCalls[] = $uri;

        $class = $this->uriClass;

        return new $class($uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $this->streamCalls[] = $content;

        $resource = Psr7\Utils::tryFopen('php://temp', 'r+');
        \fwrite($resource, $content);
        \rewind($resource);
        $class = $this->streamClass;

        return new $class($resource);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $class = $this->streamClass;

        return new $class(Psr7\Utils::tryFopen($filename, $mode));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        ++$this->streamResourceCalls;
        $class = $this->streamClass;

        return new $class($resource);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->responseCalls[] = [$code, $reasonPhrase];

        $class = $this->responseClass;

        return new $class($code, [], null, '1.1', $reasonPhrase);
    }

    /**
     * @return array<int, array{0: string, 1: mixed}>
     */
    public function requestCalls(): array
    {
        return $this->requestCalls;
    }

    /**
     * @return string[]
     */
    public function uriCalls(): array
    {
        return $this->uriCalls;
    }

    /**
     * @return string[]
     */
    public function streamCalls(): array
    {
        return $this->streamCalls;
    }

    public function streamResourceCalls(): int
    {
        return $this->streamResourceCalls;
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     */
    public function responseCalls(): array
    {
        return $this->responseCalls;
    }
}
