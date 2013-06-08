<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBodyInterface;
use Guzzle\Http\Url;

/**
 * Request factory used to create HTTP requests
 */
interface RequestFactoryInterface
{
    /**
     * Create a new request based on an HTTP message
     *
     * @param string $message HTTP message as a string
     *
     * @return RequestInterface
     */
    public function fromMessage($message);

    /**
     * Create a request from URL parts as returned from parse_url()
     *
     * @param string $method HTTP method (GET, POST, PUT, HEAD, DELETE, etc)
     *
     * @param array $urlParts URL parts containing the same keys as parse_url()
     *     - scheme: e.g. http
     *     - host:   e.g. www.guzzle-project.com
     *     - port:   e.g. 80
     *     - user:   e.g. michael
     *     - pass:   e.g. rocks
     *     - path:   e.g. / OR /index.html
     *     - query:  after the question mark ?
     * @param array|Collection                          $headers         HTTP headers
     * @param string|resource|array|EntityBodyInterface $body            Body to send in the request
     * @param string                                    $protocol        Protocol (HTTP, SPYDY, etc)
     * @param string                                    $protocolVersion 1.0, 1.1, etc
     *
     * @return RequestInterface
     */
    public function fromParts(
        $method,
        array $urlParts,
        $headers = null,
        $body = null,
        $protocol = 'HTTP',
        $protocolVersion = '1.1'
    );

    /**
     * Create a new request based on the HTTP method
     *
     * @param string                                    $method  HTTP method (GET, POST, PUT, PATCH, HEAD, DELETE, ...)
     * @param string|Url                                $url     HTTP URL to connect to
     * @param array|Collection                          $headers HTTP headers
     * @param string|resource|array|EntityBodyInterface $body    Body to send in the request
     * @param array                                     $options Array of options to apply to the request
     *
     * @return RequestInterface
     */
    public function create($method, $url, $headers = null, $body = null, array $options = array());

    /**
     * Apply an associative array of options to the request
     *
     * @param RequestInterface $request Request to update
     * @param array            $options Options to use with the request. Available options are:
     *        "headers": Associative array of headers
     *        "query": Associative array of query string values to add to the request
     *        "body": Body of a request, including an EntityBody, string, or array when sending POST requests.
     *        "auth": Basic auth array where [0] is the username, [1] is the password, and [2] (optional) is the type
     *        "cookies": Associative array of cookies
     *        "allow_redirects": Set to false to disable redirects
     *        "save_to": String, fopen resource, or EntityBody object used to store the body of the response
     *        "events": Associative array mapping event names to a closure or array of (priority, closure)
     *        "plugins": Array of plugins to add to the request
     *        "exceptions": Set to false to disable throwing exceptions on an HTTP level error (e.g. 404, 500, etc)
     *        "timeout": Float describing the timeout of the request in seconds
     *        "connect_timeout": Float describing the number of seconds to wait while trying to connect. Use 0 to wait
     *            indefinitely.
     *        "verify": Set to true to enable SSL cert validation (the default), false to disable, or supply the path
     *            to a CA bundle to enable verification using a custom certificate.
     *        "proxy": Specify an HTTP proxy (e.g. "http://username:password@192.168.16.1:10")
     *        "debug": Set to true to display all data sent over the wire
     */
    public function applyOptions(RequestInterface $request, array $options = array());
}
