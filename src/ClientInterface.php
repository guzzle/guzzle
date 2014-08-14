<?php

namespace GuzzleHttp;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\AdapterException;

/**
 * Client interface for sending HTTP requests
 */
interface ClientInterface extends HasEmitterInterface
{
    const VERSION = '4.1.8';

    /**
     * Create and return a new {@see RequestInterface} object.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string           $method  HTTP method
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return RequestInterface
     */
    public function createRequest($method, $url = null, array $options = []);

    /**
     * Send a GET request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function get($url = null, $options = []);

    /**
     * Send a HEAD request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function head($url = null, array $options = []);

    /**
     * Send a DELETE request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function delete($url = null, array $options = []);

    /**
     * Send a PUT request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function put($url = null, array $options = []);

    /**
     * Send a PATCH request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function patch($url = null, array $options = []);

    /**
     * Send a POST request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function post($url = null, array $options = []);

    /**
     * Send an OPTIONS request
     *
     * @param string|array|Url $url     URL or URI template
     * @param array            $options Array of request options to apply.
     *
     * @return ResponseInterface
     * @throws RequestException When an error is encountered
     */
    public function options($url = null, array $options = []);

    /**
     * Sends a single request
     *
     * @param RequestInterface $request Request to send
     *
     * @return \GuzzleHttp\Message\ResponseInterface
     * @throws \LogicException When the adapter does not populate a response
     * @throws RequestException When an error is encountered
     */
    public function send(RequestInterface $request);

    /**
     * Sends multiple requests in parallel.
     *
     * Exceptions are not thrown for failed requests. Callers are expected to
     * register an "error" option to handle request errors OR directly register
     * an event handler for the "error" event of a request's
     * event emitter.
     *
     * The option values for 'before', 'after', and 'error' can be a callable,
     * an associative array containing event data, or an array of event data
     * arrays. Event data arrays contain the following keys:
     *
     * - fn: callable to invoke that receives the event
     * - priority: Optional event priority (defaults to 0)
     * - once: Set to true so that the event is removed after it is triggered
     *
     * @param array|\Iterator $requests Requests to send in parallel
     * @param array           $options  Associative array of options
     *     - parallel: (int) Maximum number of requests to send in parallel
     *     - before: (callable|array) Receives a BeforeEvent
     *     - after: (callable|array) Receives a CompleteEvent
     *     - error: (callable|array) Receives a ErrorEvent
     *
     * @throws AdapterException When an error occurs in the HTTP adapter.
     */
    public function sendAll($requests, array $options = []);

    /**
     * Get default request options of the client.
     *
     * @param string|null $keyOrPath The Path to a particular default request
     *     option to retrieve or pass null to retrieve all default request
     *     options. The syntax uses "/" to denote a path through nested PHP
     *     arrays. For example, "headers/content-type".
     *
     * @return mixed
     */
    public function getDefaultOption($keyOrPath = null);

    /**
     * Set a default request option on the client so that any request created
     * by the client will use the provided default value unless overridden
     * explicitly when creating a request.
     *
     * @param string|null $keyOrPath The Path to a particular configuration
     *     value to set. The syntax uses a path notation that allows you to
     *     specify nested configuration values (e.g., 'headers/content-type').
     * @param mixed $value Default request option value to set
     */
    public function setDefaultOption($keyOrPath, $value);

    /**
     * Get the base URL of the client.
     *
     * @return string Returns the base URL if present
     */
    public function getBaseUrl();
}
