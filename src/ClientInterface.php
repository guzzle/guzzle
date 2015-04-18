<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Client interface for sending HTTP requests.
 */
interface ClientInterface
{
    const VERSION = '6.0.0-beta.1';

    /**
     * Send an HTTP request.
     *
     * @param RequestInterface $request Request to send
     * @param array            $options Request options to apply to the given
     *                                  request and to the transfer.
     *
     * @return ResponseInterface
     */
    public function send(RequestInterface $request, array $options = []);

    /**
     * Asynchronously send an HTTP request.
     *
     * @param RequestInterface $request Request to send
     * @param array            $options Request options to apply to the given
     *                                  request and to the transfer.
     *
     * @return PromiseInterface
     */
    public function sendAsync(RequestInterface $request, array $options = []);

    /**
     * Create and send an HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string                    $method  HTTP method
     * @param string|array|UriInterface $uri     URI or URI template
     * @param array                     $options Request options to apply.
     *
     * @return ResponseInterface
     */
    public function request($method, $uri = null, array $options = []);

    /**
     * Create and send an asynchronous HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string                    $method  HTTP method
     * @param string|array|UriInterface $uri     URI or URI template
     * @param array                     $options Request options to apply.
     *
     * @return ResponseInterface
     */
    public function requestAsync($method, $uri = null, array $options = []);

    /**
     * Get default request options of the client.
     *
     * @param string|null $option The default request option to retrieve.
     *
     * @return mixed
     */
    public function getDefaultOption($option = null);
}
