<?php

namespace Guzzle\Http;

use Guzzle\Guzzle;
use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactoryInterface;
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
     * Get the default HTTP headers to add to each request created by the client
     *
     * @return Collection
     */
    function getDefaultHeaders();

    /**
     * Set the default HTTP headers to add to each request created by the client
     *
     * @param array|Collection $headers Default HTTP headers
     *
     * @return ClientInterface
     */
    function setDefaultHeaders($headers);

    /**
     * Set the URI template expander to use with the client
     *
     * @param UriTemplate $uriTemplate
     *
     * @return ClientInterface
     */
    function setUriTemplate(UriTemplate $uriTemplate);

    /**
     * Get the URI template expander used by the client
     *
     * @return UriTemplate
     */
    function getUriTemplate();

    /**
     * Expand a URI template using client configuration data
     *
     * @param string $template URI template to expand
     * @param array $variables (optional) Additional variables to use in the expansion
     *
     * @return string
     */
    function expandTemplate($template, array $variables = null);

    /**
     * Create and return a new {@see RequestInterface} configured for the client
     *
     * @param string $method (optional) HTTP method.  Defaults to GET
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body (optional) Entity body of
     *      request (POST/PUT) or response (GET)
     *
     * @return RequestInterface
     */
    function createRequest($method = RequestInterface::GET, $uri = null, $headers = null, $body = null);

    /**
     * Get the client's base URL as either an expanded or raw URI template
     *
     * @param bool $expand (optional) Set to FALSE to get the raw base URL
     *    without URI template expansion
     *
     * @return string
     */
    function getBaseUrl($expand = true);

    /**
     * Set the base URL of the client
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
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
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
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return RequestInterface
     */
    function head($uri = null, $headers = null);

    /**
     * Create a DELETE request for the client
     *
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return RequestInterface
     */
    function delete($uri = null, $headers = null);

    /**
     * Create a PUT request for the client
     *
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|EntityBody $body Body to send in the request
     *
     * @return EntityEnclosingRequest
     */
    function put($uri = null, $headers = null, $body = null);

    /**
     * Create a PATCH request for the client
     *
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|EntityBody $body Body to send in the request
     *
     * @return EntityEnclosingRequest
     */
    function patch($uri = null, $headers = null, $body = null);

    /**
     * Create a POST request for the client
     *
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
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
     * @param string|array $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to
     *      append.  Use an array to provide a URI template and additional
     *      variables to use in the URI template expansion.
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
    function send($requests);

    /**
     * Set a curl multi object to be used internally by the client for
     * transferring requests.
     *
     * @param CurlMultiInterface $curlMulti Mulit object
     *
     * @return Client
     */
    function setCurlMulti(CurlMultiInterface $curlMulti);

    /**
     * Set the request factory to use with the client when creating requests
     *
     * @param RequestFactoryInterface $factory Request factory
     *
     * @return ClientInterface
     */
    function setRequestFactory(RequestFactoryInterface $factory);
}
