<?php

declare(strict_types=1);

namespace GuzzleHttp;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * This class contains a list of built-in Guzzle request options.
 *
 * @see https://github.com/guzzle/guzzle/blob/8.0/docs/request-options.md
 */
final class RequestOptions
{
    private function __construct()
    {
    }

    /**
     * allow_redirects: (bool|array) Controls redirect behavior. Pass false
     * to disable redirects, pass true to enable redirects, or pass an
     * associative array to provide custom redirect settings. Clients enable
     * redirects by default when the default redirect middleware is present.
     * This option only works if your handler has the RedirectMiddleware. When
     * passing an associative array, you can provide the following key value
     * pairs. Only 301, 302, 303, 307, and 308 responses with a Location header
     * are followed.
     *
     * - max: (int, default=5) maximum number of allowed redirects.
     * - strict: (bool, default=false) Set to true to use strict redirects
     *   meaning redirect POST requests with POST requests vs. doing what most
     *   browsers do which is redirect POST requests with GET requests. The
     *   QUERY method keeps its method and body across non-strict 301 and 302
     *   redirects, and a 303 redirect is followed with a body-less GET.
     * - referer: (bool, default=false) Set to true to enable the Referer
     *   header.
     * - protocols: (non-empty-array<array-key, 'http'|'https'>,
     *   default=['http', 'https']) Allowed redirect protocols. Values are
     *   case-sensitive; only "http" and "https" are accepted.
     * - on_redirect: (callable(RequestInterface, ResponseInterface, UriInterface): mixed)
     *   PHP callable that is invoked when a redirect is encountered. The
     *   callable is invoked with the request, the redirect response that was
     *   received, and the effective URI. Any return value is ignored. When
     *   this callback is supplied via GuzzleHttp\Pool's "options"
     *   configuration, the pool appends the request's iterable key as a
     *   fourth argument.
     * - track_redirects: (bool, default=false) Track redirected URI and status
     *   history in response headers.
     */
    public const ALLOW_REDIRECTS = 'allow_redirects';

    /**
     * auth: (array{0: string, 1: string, 2?: string|null}|string|false|null)
     * Pass an array of HTTP authentication parameters to use with the request.
     * The array must contain the username in index [0], the password in index
     * [1], and you can optionally provide a built-in authentication type in
     * index [2]. Pass false or null to disable authentication for a request.
     * Built-in Basic and Digest authentication are applied by the auth
     * middleware, normally from HandlerStack::create(). String values are
     * passed through for custom handlers. Built-in Basic usernames must not
     * contain a colon, and neither Basic usernames nor passwords may contain
     * ASCII control characters. Basic passwords may contain colons.
     */
    public const AUTH = 'auth';

    /**
     * body: (resource|string|null|StreamInterface|(callable&object)|\Iterator|\Stringable)
     * Body to send in the request. Resource and stringable object values are
     * converted using the configured stream_factory. Callable and iterator
     * bodies use Guzzle's existing stream handling. Strings are used as literal
     * body contents, even when they name a callable. Callable bodies may be
     * closures or invokable objects; arrays, int, float, bool, and generic
     * objects without __toString() are not valid body values.
     */
    public const BODY = 'body';

    /**
     * cert: (string|array{0: string, 1?: string|null}) Set to a string to
     * specify the path to a client certificate file. PEM is the default
     * certificate format. If a password is required, set cert to an array
     * containing the certificate path in the first array element followed by
     * the certificate password in the second array element. A null password is
     * treated the same as omitting it. Use cert_type to specify another
     * supported certificate format.
     *
     * @see self::CERT_TYPE
     */
    public const CERT = 'cert';

    /**
     * cert_type: (string) Specify the SSL client certificate file type.
     */
    public const CERT_TYPE = 'cert_type';

    /**
     * cookies: (false|GuzzleHttp\Cookie\CookieJarInterface, default=false)
     * Specifies whether or not cookies are used in a request or what cookie
     * jar to use or what cookies to send. This option only works if your
     * handler has the `cookie` middleware. Valid values are `false` and
     * an instance of {@see Cookie\CookieJarInterface}.
     */
    public const COOKIES = 'cookies';

    /**
     * connect_timeout: (int|float, default=60) Number of seconds to wait
     * while trying to connect to a server. Use 0 to disable the connect
     * timeout. Positive values below 0.001 seconds are rejected by the
     * built-in cURL handler.
     */
    public const CONNECT_TIMEOUT = 'connect_timeout';

    /**
     * crypto_method: (int) A value describing the minimum TLS protocol
     * version to use. The built-in cURL and stream handlers default HTTPS
     * requests to TLS 1.2 or newer.
     *
     * This setting must be set to one of the
     * ``STREAM_CRYPTO_METHOD_TLS*_CLIENT`` constants. cURL 7.52.0 or higher
     * is required to use TLS 1.3 with the cURL handler.
     */
    public const CRYPTO_METHOD = 'crypto_method';

    /**
     * crypto_method_max: (int) A value describing the maximum TLS protocol
     * version to use. The built-in cURL and stream handlers default HTTPS
     * requests to TLS 1.2 or newer, so a lower maximum requires explicitly
     * lowering crypto_method too.
     *
     * This setting must be set to one of the
     * ``STREAM_CRYPTO_METHOD_TLS*_CLIENT`` constants. cURL 7.54.0 or higher
     * is required in order to specify a maximum TLS version with the cURL
     * handler.
     */
    public const CRYPTO_METHOD_MAX = 'crypto_method_max';

    /**
     * curl: (array) Raw cURL options to apply when using a built-in cURL
     * handler.
     */
    public const CURL = 'curl';

    /**
     * debug: (bool|resource) Set to true or set to a PHP stream returned by
     * fopen() to enable debug output with the handler used to send a request.
     * For example, when using cURL to transfer requests, cURL's verbose of
     * CURLOPT_VERBOSE will be emitted. When using the PHP stream wrapper,
     * stream wrapper notifications will be emitted. If set to true, the output
     * is written to PHP's STDOUT. If a PHP stream is provided, output is
     * written to the stream.
     */
    public const DEBUG = 'debug';

    /**
     * decode_content: (bool|string, default=true) Specify whether or not
     * Content-Encoding responses (gzip, deflate, etc.) are automatically
     * decoded.
     */
    public const DECODE_CONTENT = 'decode_content';

    /**
     * delay: (int|float) The finite, non-negative amount of time to delay
     * before sending in milliseconds.
     */
    public const DELAY = 'delay';

    /**
     * expect: (bool|integer) Controls the behavior of the
     * "Expect: 100-Continue" header.
     *
     * Set to `true` to enable the "Expect: 100-Continue" header for all
     * requests that sends a body. Set to `false` to disable the
     * "Expect: 100-Continue" header for all requests. Set to a number so that
     * the size of the payload must be greater than the number in order to send
     * the Expect header. Setting to a number will send the Expect header for
     * all requests in which the size of the payload cannot be determined or
     * where the body is not rewindable.
     *
     * By default, Guzzle will add the "Expect: 100-Continue" header when the
     * size of the body of a request is greater than 1 MB and a request is
     * using HTTP/1.1.
     */
    public const EXPECT = 'expect';

    /**
     * form_params: (array<array-key, string|int|float|bool|null|array>)
     * Associative array of form field names to scalar, null, or nested array
     * values. Sets the Content-Type header to application/x-www-form-urlencoded
     * when no Content-Type header is already present.
     */
    public const FORM_PARAMS = 'form_params';

    /**
     * headers: (array<array-key, string|non-empty-array<array-key, string>>|null)
     * Associative array of HTTP headers. Each value MUST be a string or
     * non-empty array of strings.
     */
    public const HEADERS = 'headers';

    /**
     * http_errors: (bool, default=true) Set to false to disable exceptions
     * when a non-successful HTTP response is received. By default,
     * exceptions will be thrown for 4xx and 5xx responses. This option only
     * works if your handler has the `httpErrors` middleware.
     */
    public const HTTP_ERRORS = 'http_errors';

    /**
     * idn_conversion: (bool|int|null, default=false) A combination of IDNA_*
     * constants for PHP's idn_to_ascii() function. Set to false or null to
     * disable IDN support, or true to use the default configuration
     * (IDNA_DEFAULT constant).
     */
    public const IDN_CONVERSION = 'idn_conversion';

    /**
     * json: (mixed) Adds JSON data to a request. The provided value is JSON
     * encoded and a Content-Type header of application/json will be added to
     * the request if no Content-Type header is already present. An Accept
     * header is not added automatically.
     */
    public const JSON = 'json';

    /**
     * multipart: (array) Array of part arrays, each containing a required
     * "name" key mapping to the string or integer form field name, a required
     * "contents" key mapping to any non-array value accepted by PSR-7
     * Utils::streamFor() or a nested array of field values, an optional
     * "headers" array of string custom header values, and an optional
     * "filename" key mapping to a string to send as the filename in the part.
     * "headers" and "filename" cannot be used when "contents" is an array.
     */
    public const MULTIPART = 'multipart';

    /**
     * multiplex: (string, default=GuzzleHttp\Multiplexing::WAIT) Controls how a
     * request sent through a built-in cURL handler relates to shared,
     * multiplexed connections: how an HTTP/2 or HTTP/3 request pursues one, or,
     * with Multiplexing::NONE, whether the transfer may share its connection at
     * all. Use Multiplexing::EAGER to avoid waiting for pending connections,
     * Multiplexing::WAIT to wait on libcurl-eligible pending connections with
     * CURLOPT_PIPEWAIT, normally to the same origin,
     * Multiplexing::REQUIRE_EAGER to fail unless a multiplexed protocol is
     * guaranteed while dialing eagerly, or Multiplexing::REQUIRE_WAIT for the
     * same guarantee while also waiting on pending connections. The required
     * modes require a handler that permits actual multiplexing, not merely a
     * multiplexed protocol, and are rejected on a Multiplexing::NONE handler.
     * The stream handler ignores EAGER and WAIT, and rejects the required
     * family; CurlHandler has no multi handle to multiplex over. The required
     * family also rejects final CURLOPT_HTTPAUTH masks that permit NTLM, which
     * libcurl retries over HTTP/1.1. Multiplexing::NONE disables multiplexing
     * for a whole handler when passed as the "multiplex" client configuration
     * option, which configures the default handler and also becomes the default
     * request option, or, when constructing a handler directly, as the
     * CurlMultiHandler "multiplex" constructor option. A handler configured
     * with Multiplexing::NONE wins over the default WAIT: default requests run
     * without waiting, explicitly requested wait modes are rejected as a
     * configuration conflict when the transfer would actually wait, and the
     * required modes are always rejected, because they require a handler that
     * permits actual multiplexing, not merely a multiplexed protocol. As a
     * request option value, Multiplexing::NONE guarantees the transfer does not
     * share its connection with any concurrent transfer. Multiplexing::NONE
     * does not force HTTP/1.1: on a Multiplexing::NONE handler, HTTP/2 still
     * negotiates and each transfer keeps its connection to itself.
     *
     * The request option value is accepted exactly where the guarantee holds
     * and can be verified: on a CurlMultiHandler configured with
     * Multiplexing::NONE, for requests whose declared protocol version is
     * HTTP/1.x, on CurlHandler, and on the stream handler, which never
     * multiplexes. An HTTP/2 or HTTP/3 request with a Multiplexing::NONE
     * request option is rejected on a CurlMultiHandler that permits
     * multiplexing. Acceptance is decided from the request's declared protocol
     * version, before any transport-level downgrade: an HTTP/3 request sent
     * through a proxy is delivered over HTTP/2 or HTTP/1.1 on the wire, but is
     * still rejected. On a CurlMultiHandler that permits multiplexing,
     * Multiplexing::NONE is also rejected with a custom "handle_factory", when
     * the handler requires persistent transport sharing (the safeguards can
     * require a fresh connection, which required sharing forbids), when the
     * request carries an Expect: 100-continue header (its 417 retries select
     * connections outside the safeguards; remove an explicitly supplied
     * header, or set the "expect" request option to false to prevent it being
     * added automatically), and combined with a raw CURLOPT_HTTPAUTH cURL
     * option, whose authentication retries do the same.
     *
     * On a client whose multi handler permits multiplexing, the ordinary
     * non-streaming default stack - both cURL handlers available and no
     * connection caps forcing multi-only routing - runs synchronous requests on
     * the CurlHandler path, which satisfies the guarantee for any protocol
     * version, while asynchronous requests run on the CurlMultiHandler, so an
     * HTTP/2 request with Multiplexing::NONE succeeds synchronously and is
     * rejected asynchronously on the same client. Keep-alive reuse between
     * consecutive transfers is unaffected, except on libcurl versions below
     * 7.77.0 and from 8.11.0 through 8.12.1, where an accepted HTTP/1.x request
     * on a multiplexing CurlMultiHandler forces a fresh connection. Custom
     * handlers receive the "multiplex" option unchanged: its semantics are
     * handler-defined, Guzzle does not guarantee it is honored, and a
     * client-level Multiplexing::NONE with a custom handler flows to it as a
     * default request option without client-side enforcement.
     */
    public const MULTIPLEX = 'multiplex';

    /**
     * on_headers: (callable(ResponseInterface, RequestInterface): mixed) A
     * callable that is invoked when the HTTP headers of the final response, or
     * a 101 Switching Protocols response, have been received but the body has
     * not yet begun to download. The callable is passed the response and
     * request as {@see ResponseInterface} and {@see RequestInterface} objects,
     * respectively. If it throws, the request promise is rejected with a
     * GuzzleHttp\Exception\ResponseException (a RequestException subtype)
     * wrapping the thrown exception. When this callback is supplied via
     * GuzzleHttp\Pool's "options" configuration, the pool appends the request's
     * iterable key as a third argument.
     */
    public const ON_HEADERS = 'on_headers';

    /**
     * on_stats: (callable(TransferStats): mixed) allows you to get access to
     * transfer statistics of a request and access the lower level transfer
     * details of the handler associated with your client. ``on_stats`` is a
     * callable that is invoked when a handler has finished sending a request.
     * The callback is invoked with transfer statistics about the request, the
     * response received, or the error encountered. Included in the data is the
     * total amount of time taken to send the request. Exceptions thrown by
     * on_stats are not wrapped by Guzzle. Built-in handlers reject non-callable
     * values before starting the transfer. The built-in cURL handlers release
     * native easy handles before invoking on_stats and invoke it per low-level
     * transfer attempt. When this callback is supplied via GuzzleHttp\Pool's
     * "options" configuration, the pool appends the request's iterable key as a
     * second argument.
     */
    public const ON_STATS = 'on_stats';

    /**
     * on_trailers: (callable(array<string, list<string>>, ResponseInterface, RequestInterface): mixed)
     * A callable that is invoked exactly once when a transfer completes
     * successfully, with the HTTP trailer fields of the response. The callable
     * is passed an associative array of trailer field names mapped to lists of
     * field values, the response, and the request. The array is empty when the
     * response carried no trailer fields, and trailer field names are
     * lowercased and grouped case-insensitively while values keep their wire
     * order. The callable is never invoked for failed transfers. If it throws,
     * the request promise is rejected with a
     * GuzzleHttp\Exception\ResponseException (a RequestException subtype)
     * wrapping the thrown exception. Only the built-in cURL handlers invoke
     * on_trailers; the built-in stream handler rejects the option because it
     * cannot observe trailer fields, and the mock handler ignores it.
     * Malformed trailer field lines are discarded before parsing. Trailer
     * fields are reported separately from response headers and are never
     * merged into the response. When this callback is supplied via
     * GuzzleHttp\Pool's "options" configuration, the pool appends the
     * request's iterable key as a fourth argument.
     */
    public const ON_TRAILERS = 'on_trailers';

    /**
     * progress: (callable(int, int, int, int): mixed)
     * Defines a function to invoke when transfer progress is made. The function
     * accepts the following positional arguments: the total number of bytes
     * expected to be downloaded, the number of bytes downloaded so far, the
     * number of bytes expected to be uploaded, the number of bytes uploaded so
     * far. With the built-in cURL handlers, returning a truthy value aborts the
     * transfer and throwing rejects the promise with a RequestException. The
     * built-in stream handler ignores return values. When this callback is
     * supplied via GuzzleHttp\Pool's "options" configuration, the pool appends
     * the request's iterable key as a fifth argument.
     */
    public const PROGRESS = 'progress';

    /**
     * protocols: (non-empty-array<array-key, 'http'|'https'>,
     * default=['http', 'https']) Allowed URI schemes. Values are
     * case-sensitive; only "http" and "https" are accepted.
     */
    public const PROTOCOLS = 'protocols';

    /**
     * proxy: (string|array) Pass a string to specify an HTTP proxy, or an array
     * to specify different proxies for different protocols (where the key is
     * the protocol and the value is a proxy string or null). Provide a "no" key
     * as a comma- or whitespace-delimited string, array of strings, or null to
     * specify hosts, host-and-port pairs, IP literals, IP CIDR rules, or
     * wildcard rules that should not be proxied. Domain rules are matched
     * case-insensitively. Exact IP literals are normalized before matching.
     * CIDR rules match IP literals only and are not port-specific. Custom
     * handlers can use ProxyOptions::resolve() to apply Guzzle-compatible proxy
     * selection; the built-in handlers' environment-variable fallback is not
     * part of that helper.
     */
    public const PROXY = 'proxy';

    /**
     * query: (array<array-key, mixed>|string) Associative array of query string
     * values to add to the request. This option uses PHP's http_build_query()
     * to create the string representation. Pass a string value if you need
     * more control than what this method provides
     */
    public const QUERY = 'query';

    /**
     * request_factory: (Psr\Http\Message\RequestFactoryInterface) PSR-17
     * request factory used when creating requests through request() and
     * requestAsync().
     */
    public const REQUEST_FACTORY = 'request_factory';

    /**
     * stream_factory: (Psr\Http\Message\StreamFactoryInterface) PSR-17
     * stream factory used when creating request body streams from body,
     * form_params, and json request options, and when the built-in handlers
     * create response body streams.
     */
    public const STREAM_FACTORY = 'stream_factory';

    /**
     * response_factory: (Psr\Http\Message\ResponseFactoryInterface) PSR-17
     * response factory used by the built-in handlers when creating the
     * response message.
     */
    public const RESPONSE_FACTORY = 'response_factory';

    /**
     * sink: (resource|string|StreamInterface) Where the data of the
     * response is written to. Defaults to a PHP temp stream. Providing a
     * string will write data to a file by the given name. Built-in handlers
     * treat PHP resources as caller-owned; callers are responsible for closing
     * resource sinks.
     */
    public const SINK = 'sink';

    /**
     * synchronous: (bool) Set to true to inform HTTP handlers that you intend
     * on waiting on the response. This can be useful for optimizations. Note
     * that a promise is still returned if you are using one of the async
     * client methods.
     */
    public const SYNCHRONOUS = 'synchronous';

    /**
     * ssl_key: (array{0: string, 1?: string|null}|string) Specify the path to
     * a private SSL key file. PEM is the default private key format. If a
     * password is required, set ssl_key to an array containing the key path in
     * the first array element followed by the key password in the second
     * element. A null password is treated the same as omitting it. Use
     * ssl_key_type to specify another supported key format.
     *
     * @see self::SSL_KEY_TYPE
     */
    public const SSL_KEY = 'ssl_key';

    /**
     * ssl_key_type: (string) Specify the SSL private key file type.
     */
    public const SSL_KEY_TYPE = 'ssl_key_type';

    /**
     * stream: (bool) Set to true to attempt to stream a response rather than
     * download it all up-front.
     */
    public const STREAM = 'stream';

    /**
     * stream_context: (array) PHP stream context options to merge into the
     * context used by the built-in stream handler.
     */
    public const STREAM_CONTEXT = 'stream_context';

    /**
     * verify: (bool|string, default=true) Describes the SSL certificate
     * verification behavior of a request. Set to true to enable SSL
     * certificate verification using the system CA bundle when available
     * (the default). Set to false to disable certificate verification (this
     * is insecure!). Set to a string to provide the path to a CA bundle on
     * disk to enable verification using a custom certificate.
     */
    public const VERIFY = 'verify';

    /**
     * timeout: (int|float, default=0) Number of seconds to use as the total
     * timeout of the request. Use 0 to disable the total timeout (the default
     * behavior). Positive values below 0.001 seconds are rejected by the
     * built-in handlers.
     */
    public const TIMEOUT = 'timeout';

    /**
     * read_timeout: (int|float, default=60) Number of seconds the connection
     * may sit silent at any stage of the request. Use 0 to disable the idle
     * timeout. Positive values below 0.001 seconds are rejected by the
     * built-in stream handler.
     */
    public const READ_TIMEOUT = 'read_timeout';

    /**
     * retries: (int) Current retry count used by the retry middleware.
     */
    public const RETRIES = 'retries';

    /**
     * uri_factory: (Psr\Http\Message\UriFactoryInterface) PSR-17 URI factory
     * used when creating URI objects from string request URI, base_uri, and
     * redirect Location values.
     */
    public const URI_FACTORY = 'uri_factory';

    /**
     * version: (string|int|float, default=1.1) Specifies the HTTP protocol
     * version to attempt to use.
     *
     * Guzzle defaults to HTTP/1.1. The built-in stream handler supports
     * HTTP/1.0 and HTTP/1.1. The built-in cURL handler also supports HTTP/2
     * and HTTP/3 when the installed cURL stack reports those features. For
     * HTTP/2 and HTTP/3, libcurl may use a lower HTTP version when
     * negotiation or connection setup falls back.
     */
    public const VERSION = 'version';

    /**
     * force_ip_resolve: ("v4"|"v6") Set to "v4" to force IPv4 resolution or
     * "v6" to force IPv6 resolution when supported by the handler.
     */
    public const FORCE_IP_RESOLVE = 'force_ip_resolve';
}
