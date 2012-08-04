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
     *
     * @return RequestInterface
     */
    public function create($method, $url, $headers = null, $body = null);
}
