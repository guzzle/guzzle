<?php

namespace Guzzle\Http;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Http\Exception\TransferException;
use Guzzle\Stream\ReadableStreamInterface;
use Guzzle\Url\Url;

/**
 * Client interface for sending HTTP requests
 */
interface ClientInterface extends HasDispatcherInterface
{
    /**
     * Create and return a new {@see RequestInterface} object.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string                                  $method  HTTP method
     * @param string|array                            $url     Resource URL
     * @param array                                   $headers Request headers
     * @param string|ReadableStreamInterface|resource $body    Body to send
     * @param array                                   $options Array of options to apply to the request
     *
     * @return RequestInterface
     */
    public function createRequest($method, $url = null, array $headers = [], $body = null, array $options = []);

    /**
     * Send a GET request
     *
     * @param string|array|Url $url     Resource URL
     * @param array            $headers Request headers
     * @param array            $options Options to apply to the request
     *
     * @return ResponseInterface
     */
    public function get($url = null, array $headers = [], $options = []);

    /**
     * Send a HEAD request
     *
     * @param string|array|Url $url     Absolute or relative URL
     * @param array            $headers Request headers
     * @param array            $options Options to apply to the request
     *
     * @return ResponseInterface
     */
    public function head($url = null, array $headers = [], array $options = []);

    /**
     * Send a DELETE request
     *
     * @param string|array|Url $url     Resource URL
     * @param array            $headers Request headers
     * @param array            $options Options to apply to the request
     *
     * @return ResponseInterface
     */
    public function delete($url = null, array $headers = [], array $options = []);

    /**
     * Send a PUT request
     *
     * @param string|array|Url                        $url     Resource URL
     * @param array                                   $headers Request headers
     * @param string|ReadableStreamInterface|resource $body    Body to send
     * @param array                                   $options Options to apply to the request
     *
     * @return ResponseInterface
     */
    public function put($url = null, array $headers = [], $body = null, array $options = []);

    /**
     * Send a PATCH request
     *
     * @param string|array|Url                        $url     Resource URL
     * @param array                                   $headers Request headers
     * @param string|ReadableStreamInterface|resource $body    Body to send
     * @param array                                   $options Options to apply to the request
     *
     * @return ResponseInterface
     */
    public function patch($url = null, array $headers = [], $body = null, array $options = []);

    /**
     * Send an OPTIONS request
     *
     * @param string|array|Url $url     Resource URL
     * @param array            $headers Request headers
     * @param array            $options Options to apply to the request
     *
     * @return ResponseInterface
     */
    public function options($url = null, array $headers = [], array $options = []);

    /**
     * Sends a single request
     *
     * @param RequestInterface $request Request to send
     *
     * @return \Guzzle\Http\Message\ResponseInterface
     * @throws \LogicException When the adapter does not populate a response
     * @throws TransferException When an error is encountered (network or HTTP errors)
     */
    public function send(RequestInterface $request);

    /**
     * Get the client's base URL
     *
     * @return string|null
     */
    public function getBaseUrl();
}
