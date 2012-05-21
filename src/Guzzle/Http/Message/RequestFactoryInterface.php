<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
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
    function fromMessage($message);

    /**
     * Create a request from URL parts as returned from parse_url()
     *
     * @param string $method HTTP method (GET, POST, PUT, HEAD, DELETE, etc)
     *
     * @param array $urlParts URL parts containing the same keys as parse_url()
     *     # scheme - e.g. http
     *     # host - e.g. www.guzzle-project.com
     *     # port - e.g. 80
     *     # user - e.g. michael
     *     # pass - e.g. rocks
     *     # path - e.g. / OR /index.html
     *     # query - after the question mark ?
     *
     * @param array|Collection                 $headers         HTTP headers
     * @param string|resource|array|EntityBody $body            Body to send in the request
     * @param string                           $protocol        Protocol (HTTP, SPYDY, etc)
     * @param string                           $protocolVersion 1.0, 1.1, etc
     *
     * @return RequestInterface
     */
    function fromParts($method, array $urlParts, $headers = null, $body = null, $protocol = 'HTTP', $protocolVersion = '1.1');

    /**
     * Create a new request based on the HTTP method
     *
     * @param string $method  HTTP method (GET, POST, PUT, HEAD, DELETE, etc)
     * @param string $url|Url HTTP URL to connect to.  The URI scheme, host header,
     *      and URI are parsed from the full URL.  If query string parameters
     *      are present they will be parsed as well.
     * @param array|Collection                 $headers HTTP headers
     * @param string|resource|array|EntityBody $body    Body to send in the request
     *
     * @return RequestInterface
     */
    function create($method, $url, $headers = null, $body = null);
}
