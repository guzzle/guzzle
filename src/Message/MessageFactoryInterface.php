<?php

namespace GuzzleHttp\Message;

use GuzzleHttp\Url;

/**
 * Request and response factory
 */
interface MessageFactoryInterface
{
    /**
     * Create a new request based on the HTTP method
     *
     * @param string                                $method  HTTP method (GET, POST, PUT, PATCH, HEAD, DELETE, ...)
     * @param string|Url                            $url     HTTP URL to connect to
     * @param array                                 $options Array of options to apply to the request
     *     - "headers": Associative array of headers to add to the request.
     *     - "body": string|resource|array|StreamInterface that represents the body to send over the wire.
     *     - "query": Associative array of query string values to add to the request.
     *     - "auth": Array of HTTP authentication parameters to use with the request. The array must contain the
     *       username in index [0], the password in index [1], and can optionally contain the authentication type
     *       in index [2]. The authentication types are: "Basic", "Digest", "NTLM", (defaults to "Basic").
     *       The selected authentication type must be supported by the adapter used by a client.
     *     - "version": The HTTP protocol version to use with the request. Defaults to 1.1.
     *     - "cookies": Pass an associative array containing cookies to send in the request and start a new cookie
     *       session, set to a {@see GuzzleHttp\Cookie\CookieJarInterface} object to us an existing
     *       cookie jar, or set to ``true`` to use a shared cookie session associated with the client.
     *     - "allow_redirects": Set to false to disable redirects. Set to true to enable normal redirects with a maximum
     *       number of 5 redirects. Pass an associative array containing the 'max' key to specify the maximum number of
     *       redirects and optionally provide a 'strict' key value to specify whether or not to use strict RFC
     *       compliant redirects (meaning redirect POST requests with POST requests vs. doing what most browsers do
     *       which is redirect POST requests with GET requests).
     *     - "save_to": Specify where the body of a response will be saved. Pass a string to specify the path to a file
     *       that will store the contents of the response body. Pass a resource returned from fopen to write the
     *       response to a PHP stream. Pass a {@see GuzzleHttp\Stream\StreamInterface} object to stream the response body
     *       to an open Guzzle stream.
     *     - "events": Associative array mapping event names to a callable or an associative array containing the 'fn'
     *       key that maps to a callable, an optional 'priority' key used to specify the event priority, and an optional
     *       'once' key used to specify if the event should remove itself the first time it is triggered.
     *     - "subscribers": Array of event subscribers to add to the request. Each value in the array must be an
     *       instance of {@see GuzzleHttp\Event\SubscriberInterface}.
     *     - "exceptions": Set to false to disable throwing exceptions on an HTTP protocol error (e.g. 404, 500, etc).
     *       Exceptions are thrown by default when HTTP protocol errors are encountered.
     *     - "timeout": Float describing the timeout of the request in seconds. Use 0 to wait indefinitely.
     *     - "connect_timeout": Float describing the number of seconds to wait while trying to connect. Use 0 to wait
     *       indefinitely. This setting must be supported by the adapter used to send a request.
     *     - "verify": Set to true to enable SSL cert validation (the default), false to disable validation, or supply
     *       the path to a CA bundle to enable verification using a custom certificate.
     *     - "cert": Set to a string to specify the path to a file containing a PEM formatted certificate. If a password
     *       is required, then set an array containing the path to the PEM file followed by the the password required
     *       for the certificate.
     *     - "ssl_key": Specify the path to a file containing a private SSL key in PEM format. If a password is
     *       required, then set an array containing the path to the SSL key followed by the password required for the
     *       certificate.
     *     - "proxy": Specify an HTTP proxy (e.g. "http://username:password@192.168.16.1:10")
     *     - "debug": Set to true or a PHP fopen stream resource to enable debug output with the adapter used to send a
     *       request. For example, when using cURL to transfer requests, cURL's verbose output will be emitted. When
     *       using the PHP stream wrapper, stream wrapper notifications will be emitted. If set to true, the output is
     *       written to PHP's STDOUT.
     *     - "stream": Set to true to stream a response rather than download it all up-front. (Note: This option might
     *       not be supported by every HTTP adapter, but the interface of the response object remains the same.)
     *     - "expect": Set to true to enable the "Expect: 100-Continue" header for a request that send a body. Set to
     *       false to disable "Expect: 100-Continue". Set to a number so that the size of the payload must be greater
     *       than the number in order to send the Expect header. Setting to a number will send the Expect header for
     *       all requests in which the size of the payload cannot be determined or where the body is not rewindable.
     *     - "config": Associative array of config options that are forwarded to a request's configuration collection.
     *       These values are used as configuration options that can be consumed by plugins and adapters.
     *
     * @return RequestInterface
     * @link http://docs.guzzlephp.org/clients.html#request-options In-depth reference information about each option
     */
    public function createRequest($method, $url, array $options = []);

    /**
     * Creates a response
     *
     * @param string $statusCode HTTP status code
     * @param array  $headers    Response headers
     * @param mixed  $body       Response body
     * @param array  $options    Response options
     *     - protocol_version: HTTP protocol version
     *     - header_factory: Factory used to create headers
     *     - *: Any other options used by a concrete message implementation
     *
     * @return ResponseInterface
     */
    public function createResponse($statusCode, array $headers = [], $body = null, array $options = []);
}
