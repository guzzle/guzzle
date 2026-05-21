<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    public function testRejectsEmptyRequestProtocolVersion(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = self::requestWithProtocolVersion('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP protocol version must not be empty.');

        $client->send($request);
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP protocol version must be a valid HTTP version number.');

        $client->send(self::requestWithProtocolVersion($version));
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
        $config = self::readClientConfig($client);
        self::assertArrayHasKey('base_uri', $config);
        self::assertInstanceOf(Uri::class, $config['base_uri']);
        self::assertSame('http://foo.com', (string) $config['base_uri']);
        self::assertArrayHasKey('handler', $config);
        self::assertNotNull($config['handler']);
        self::assertArrayHasKey('timeout', $config);
        self::assertSame(2, $config['timeout']);
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
        $config = self::readClientConfig($client);
        self::assertSame('http://bar.com', (string) $config['base_uri']);
        $request = new Request('GET', '/baz');
        $client->send($request);
        self::assertSame(
            'http://bar.com/baz',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testMergesDefaultOptionsAndDoesNotOverwriteUa(): void
    {
        $client = new Client(['headers' => ['User-agent' => 'foo']]);
        $config = self::readClientConfig($client);
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
        $this->expectExceptionMessage('allow_redirects must be true, false, or array');
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
        $this->expectExceptionMessage('cookies must be an instance of GuzzleHttp\\Cookie\\CookieJarInterface');
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
        $client->get('http://foo.com', ['handler' => $mock]);
        self::assertSame([\CURLOPT_ENCODING => ''], $mock->getLastOptions()['curl']);
    }

    public function testValidatesHeaders(): void
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('http://foo.com', ['headers' => 'foo']);
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

    public function testAuthCanBeTrue(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => false]);
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

    public function testAuthCanBeArrayForBasicAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b']]);
        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanBeArrayForExplicitBasicAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'basic']]);
        $last = $mock->getLastRequest();
        self::assertSame('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanBeArrayForDigestAuth(): void
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

    public function testAuthCanBeArrayForNtlmAuth(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'ntlm']]);
        $last = $mock->getLastOptions();
        self::assertSame([
            \CURLOPT_HTTPAUTH => 8,
            \CURLOPT_USERPWD => 'a:b',
        ], $last['curl']);
    }

    public function testAuthCanBeCustomType(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => 'foo']);
        $last = $mock->getLastOptions();
        self::assertSame('foo', $last['auth']);
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

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $client->get('http://foo.com', ['auth' => $auth]);
    }

    public static function invalidAuthOptionProvider(): array
    {
        return [
            [[]],
            [['user']],
            [[['user'], 'pass']],
            [['user', ['pass']]],
            [['user', 'pass', null]],
            [['user', 'pass', 1]],
            [['user', 'pass', []]],
            [['user', 'pass', 'unknown']],
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
            $config = self::readClientConfig($client);
            self::assertArrayNotHasKey('proxy', $config);

            \putenv('HTTP_PROXY=127.0.0.1');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['http' => '127.0.0.1'], $config['proxy']);

            \putenv('HTTPS_PROXY=127.0.0.2');
            \putenv('NO_PROXY= 127.0.0.3 , 127.0.0.4 , [::1]:8080 ');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(
                ['http' => '127.0.0.1', 'https' => '127.0.0.2', 'no' => ['127.0.0.3', '127.0.0.4', '[::1]:8080']],
                $config['proxy']
            );

            \putenv('HTTP_PROXY=');
            \putenv('HTTPS_PROXY=');
            \putenv('NO_PROXY=0');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['no' => ['0']], $config['proxy']);

            \putenv('NO_PROXY= , , ');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayNotHasKey('proxy', $config);

            \putenv('HTTP_PROXY=127.0.0.1');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['http' => '127.0.0.1'], $config['proxy']);

            \putenv('HTTP_PROXY=');

            \putenv('NO_PROXY=exa mple.com, foo.com');
            $client = new Client();
            $config = self::readClientConfig($client);
            self::assertArrayHasKey('proxy', $config);
            self::assertSame(['no' => ['exa mple.com', 'foo.com']], $config['proxy']);
        } finally {
            \putenv('HTTP_PROXY=');
            \putenv('HTTPS_PROXY=');
            \putenv('NO_PROXY=');
        }
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

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $client->send($request, ['headers' => ['X-Foo: Bar']]);
    }

    public function testSendWithInvalidHeaders(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('GET', 'http://foo.com');

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $client->send($request, ['headers' => ['X-Foo: Bar', 'X-Test: Fail']]);
    }

    public function testCanSetCustomHandler(): void
    {
        $mock = new MockHandler([new Response(500)]);
        $client = new Client(['handler' => $mock]);
        $mock2 = new MockHandler([new Response(200)]);
        self::assertSame(
            200,
            $client->send(new Request('GET', 'http://foo.com'), [
                'handler' => $mock2,
            ])->getStatusCode()
        );
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
     * @param float|string $version
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

        $config = self::readClientConfig($client);

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

    /**
     * @requires extension idn
     */
    public function testExceptionOnInvalidIdn(): void
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
    public function testIdnBaseUri(): void
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

    private static function readClientConfig(Client $client): array
    {
        $readConfig = \Closure::bind(static function (Client $client): array {
            return $client->config;
        }, null, Client::class);

        return $readConfig($client);
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
