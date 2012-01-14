<?php

namespace Guzzle\Http;

use Guzzle\Guzzle;
use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Curl\CurlMultiInterface;

/**
 * Client interface for send HTTP requests
 */
interface ClientInterface extends HasDispatcherInterface
{
    /**
     * Set the configuration object to use with the client
     *
     * @param array|Collection|string $config Parameters that define how the
     *      client behaves and connects to a webservice.  Pass an array or a
     *      Collection object.
     *
     * @return ClientInterface
     */
    function setConfig($config);

    /**
     * Get a configuration setting or all of the configuration settings
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE
     *      to retrieve all values of the client.  The object return can be
     *      modified, and modifications will affect the client's config.
     *
     * @return mixed|Collection
     */
    function getConfig($key = false);

    /**
     * Inject configuration values into a formatted string with {{param}} as a
     * parameter delimiter (replace param with the configuration value name)
     *
     * @param string $string String to inject config values into
     *
     * @return string
     */
    function inject($string);

    /**
     * Create and return a new {@see RequestInterface} configured for the client
     *
     * @param string $method (optional) HTTP method.  Defaults to GET
     * @param string $uri (optional) Resource URI.  Use an absolute path to
     *      override the base path of the client, or a relative path to append
     *      to the base path of the client.  The URI can contain the
     *      querystring as well.
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body (optional) Entity body of
     *      request (POST/PUT) or response (GET)
     *
     * @return RequestInterface
     */
    function createRequest($method = RequestInterface::GET, $uri = null, $headers = null, $body = null);

    /**
     * Prepare a request to be sent from the Client by adding client specific
     * behaviors and properties to the request.
     *
     * This method should only be called when using the default RequestFactory
     * is not an option and the request sent from the client must be created
     * manually.
     *
     * @param RequestInterface $request Request to prepare for the client
     *
     * @return RequestInterface
     */
    function prepareRequest(RequestInterface $request);

    /**
     * Get the base service endpoint URL with configuration options injected
     * into the configuration setting.
     *
     * @param bool $inject (optional) Set to FALSE to get the raw base URL
     *
     * @return string
     * @throws RuntimeException if a base URL has not been set
     */
    function getBaseUrl($inject = true);

    /**
     * Set the base service endpoint URL
     *
     * @param string $url The base service endpoint URL of the webservice
     *
     * @return ClientInterface
     */
    function setBaseUrl($url);

    /**
     * Set the name of your application and application version that will be
     * appended to the User-Agent header of all reqeusts.
     *
     * @param string $userAgent User agent string
     * @param bool $includeDefault (optional) Set to TRUE to append the default
     *    Guzzle user agent
     *
     * @return ClientInterface
     */
    function setUserAgent($userAgent, $includeDefault = false);

    /**
     * Create a GET request for the client
     *
     * @param string $path (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body (optional) Where to store
     *      the response entity body
     *
     * @return RequestInterface
     */
    function get($uri = null, $headers = null, $body = null);

    /**
     * Create a HEAD request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return RequestInterface
     */
    function head($uri = null, $headers = null);

    /**
     * Create a DELETE request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return RequestInterface
     */
    function delete($uri = null, $headers = null);

    /**
     * Create a PUT request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body Body to send in the request
     *
     * @return EntityEnclosingRequest
     */
    function put($uri = null, $headers = null, $body = null);

    /**
     * Create a POST request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an absolute path to
     *      override the base path, or a relative path to append it.
     * @param array|Collection $headers (optional) HTTP headers
     * @param array|Collection|string|EntityBody $postBody (optional) POST
     *      body.  Can be a string, EntityBody, or associative array of POST
     *      fields to send in the body of the request.  Prefix a value in the
     *      array with the @ symbol reference a file.
     *
     * @return EntityEnclosingRequest
     */
    function post($uri = null, $headers = null, $postBody = null);

    /**
     * Create an OPTIONS request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or relative path to append
     *
     * @return RequestInterface
     */
    function options($uri = null);

    /**
     * Sends a single request or an array of requests in parallel
     *
     * @param array $requests Request(s) to send
     *
     * @return array Returns the response(s)
     */
    public function send($requests);

    /**
     * Set a curl multi object to be used internally by the client for
     * transferring requests.
     *
     * @param CurlMultiInterface $curlMulti Mulit object
     *
     * @return Client
     */
    public function setCurlMulti(CurlMultiInterface $curlMulti);
}