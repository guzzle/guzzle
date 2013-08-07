<?php

namespace Guzzle\Http\Message;

use Guzzle\Url\Url;
use Guzzle\Stream\StreamInterface;

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
     * @param array                                 $headers HTTP request headers
     * @param string|resource|array|StreamInterface $body    Body to send in the request
     * @param array                                 $options Array of options to apply to the request
     *        "headers": Associative array of headers
     *        "query": Associative array of query string values to add to the request
     *        "auth": Array of HTTP authentication parameters to use with the request. The array must contain the
     *            username in index [0], the password in index [2], and can optionally contain the authentication type
     *            in index [3]. The authentication types are: "Basic", "Digest", "NTLM", "Any" (defaults to "Basic").
     *        "cookies": Associative array of cookies
     *        "allow_redirects": Set to false to disable redirects. Set to "strict" to enable strict redirects that
     *            convert POST requests to GET requests on a redirect.
     *        "save_to": String, fopen resource, or EntityBody object used to store the body of the response
     *        "events": Associative array mapping event names to a closure or array of (priority, closure)
     *        "plugins": Array of plugins to add to the request
     *        "exceptions": Set to false to disable throwing exceptions on an HTTP level error (e.g. 404, 500, etc)
     *        "timeout": Float describing the timeout of the request in seconds
     *        "connect_timeout": Float describing the number of seconds to wait while trying to connect. Use 0 to wait
     *            indefinitely.
     *        "verify": Set to true to enable SSL cert validation (the default), false to disable, or supply the path
     *            to a CA bundle to enable verification using a custom certificate.
     *        "cert": Set to a string to specify the path to a file containing a PEM formatted certificate. If a
     *            password is required, then set an array containing the path to the PEM file followed by the the
     *            password required for the certificate.
     *        "ssl_key": Specify the path to a file containing a private SSL key in PEM format. If a password is
     *            required, then set an array containing the path to the SSL key followed by the password required for
     *            the certificate.
     *        "proxy": Specify an HTTP proxy (e.g. "http://username:password@192.168.16.1:10")
     *        "debug": Set to true to display all data sent over the wire
     *        "request_config": Associative array of options that are forwarded to a request's config collection.
     *            These values are used as configuration options that can be consumed by plugins and adapters.
     *        "expect": Set to true to enable "Expect: 100-Continue" headers for requests that send a body. Set to
     *            false to disable for all requests. So to a number so that the size of the payload must be greater
     *            than the number in order to send the Expect header. Setting to a number will send the Expect header
     *            for all requests in which the size of the payload cannot be determined or where the body is not
     *            rewindable.
     *
     * @return RequestInterface
     */
    public function createRequest($method, $url, array $headers = [], $body = null, array $options = array());

    /**
     * Create a response object
     *
     * @return ResponseInterface
     */
    public function createResponse();
}
