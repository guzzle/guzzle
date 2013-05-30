<?php

namespace Guzzle\Http;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactoryInterface;
use Guzzle\Parser\UriTemplate\UriTemplateInterface;
use Guzzle\Http\Curl\CurlMultiInterface;

/**
 * Client interface for send HTTP requests
 */
interface ClientInterface extends HasDispatcherInterface
{
    const CREATE_REQUEST = 'client.create_request';

    /** @var string RFC 1123 HTTP-Date */
    const HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * Set the configuration object to use with the client
     *
     * @param array|Collection|string $config Parameters that define how the client behaves and connects to a
     *                                        webservice. Pass an array or a Collection object.
     * @return ClientInterface
     */
    public function setConfig($config);

    /**
     * Get a configuration setting or all of the configuration settings
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE to retrieve all values of the client.
     *                         The object return can be modified, and modifications will affect the client's config.
     * @return mixed|Collection
     */
    public function getConfig($key = false);

    /**
     * Set SSL verification options.
     *
     * Setting $certificateAuthority to TRUE will result in the bundled
     * cacert.pem being used to verify against the remote host.
     *
     * Alternate certificates to verify against can be specified with the
     * $certificateAuthority option set to a certificate file location to be
     * used with CURLOPT_CAINFO, or a certificate directory path to be used
     * with the CURLOPT_CAPATH option.
     *
     * Setting $certificateAuthority to FALSE will turn off peer verification,
     * unset the bundled cacert.pem, and disable host verification. Please
     * don't do this unless you really know what you're doing, and why
     * you're doing it.
     *
     * @param string|bool $certificateAuthority bool, file path, or directory path
     * @param bool        $verifyPeer           FALSE to stop cURL from verifying the peer's certificate.
     * @param int         $verifyHost           Set the cURL handle's CURLOPT_SSL_VERIFYHOST option
     *
     * @return ClientInterface
     */
    public function setSslVerification($certificateAuthority = true, $verifyPeer = true, $verifyHost = 2);

    /**
     * Get the default HTTP headers to add to each request created by the client
     *
     * @return Collection
     */
    public function getDefaultHeaders();

    /**
     * Set the default HTTP headers to add to each request created by the client
     *
     * @param array|Collection $headers Default HTTP headers
     *
     * @return ClientInterface
     */
    public function setDefaultHeaders($headers);

    /**
     * Expand a URI template using client configuration data
     *
     * @param string $template  URI template to expand
     * @param array  $variables Additional variables to use in the expansion
     *
     * @return string
     */
    public function expandTemplate($template, array $variables = null);

    /**
     * Create and return a new {@see RequestInterface} configured for the client.
     *
     * Use an absolute path to override the base path of the client, or a relative path to append to the base path of
     * the client. The URI can contain the query string as well.  Use an array to provide a URI template and additional
     * variables to use in the URI template expansion.
     *
     * @param string                                    $method  HTTP method.  Defaults to GET
     * @param string|array                              $uri     Resource URI.
     * @param array|Collection                          $headers HTTP headers
     * @param string|resource|array|EntityBodyInterface $body    Entity body of request (POST/PUT) or response (GET)
     *
     * @return RequestInterface
     * @throws InvalidArgumentException if a URI array is passed that does not contain exactly two elements: the URI
     *                                  followed by template variables
     */
    public function createRequest($method = RequestInterface::GET, $uri = null, $headers = null, $body = null);

    /**
     * Get the client's base URL as either an expanded or raw URI template
     *
     * @param bool $expand Set to FALSE to get the raw base URL without URI template expansion
     *
     * @return string|null
     */
    public function getBaseUrl($expand = true);

    /**
     * Set the base URL of the client
     *
     * @param string $url The base service endpoint URL of the webservice
     *
     * @return ClientInterface
     */
    public function setBaseUrl($url);

    /**
     * Set the User-Agent header to be used on all requests from the client
     *
     * @param string $userAgent      User agent string
     * @param bool   $includeDefault Set to true to prepend the value to Guzzle's default user agent string
     *
     * @return self
     */
    public function setUserAgent($userAgent, $includeDefault = false);

    /**
     * Create a GET request for the client
     *
     * @param string|array                              $uri     Resource URI
     * @param array|Collection                          $headers HTTP headers
     * @param string|resource|array|EntityBodyInterface $body    Where to store the response entity body
     *
     * @return RequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function get($uri = null, $headers = null, $body = null);

    /**
     * Create a HEAD request for the client
     *
     * @param string|array     $uri     Resource URI
     * @param array|Collection $headers HTTP headers
     *
     * @return RequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function head($uri = null, $headers = null);

    /**
     * Create a DELETE request for the client
     *
     * @param string|array     $uri     Resource URI
     * @param array|Collection $headers HTTP headers
     * @param string|resource|EntityBodyInterface $body    Body to send in the request
     *
     * @return EntityEnclosingRequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function delete($uri = null, $headers = null, $body = null);

    /**
     * Create a PUT request for the client
     *
     * @param string|array                        $uri     Resource URI
     * @param array|Collection                    $headers HTTP headers
     * @param string|resource|EntityBodyInterface $body    Body to send in the request
     *
     * @return EntityEnclosingRequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function put($uri = null, $headers = null, $body = null);

    /**
     * Create a PATCH request for the client
     *
     * @param string|array                        $uri     Resource URI
     * @param array|Collection                    $headers HTTP headers
     * @param string|resource|EntityBodyInterface $body    Body to send in the request
     *
     * @return EntityEnclosingRequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function patch($uri = null, $headers = null, $body = null);

    /**
     * Create a POST request for the client
     *
     * @param string|array                                $uri      Resource URI
     * @param array|Collection                            $headers  HTTP headers
     * @param array|Collection|string|EntityBodyInterface $postBody POST body. Can be a string, EntityBody, or
     *                                                    associative array of POST fields to send in the body of the
     *                                                    request.  Prefix a value in the array with the @ symbol to
     *                                                    reference a file.
     * @return EntityEnclosingRequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function post($uri = null, $headers = null, $postBody = null);

    /**
     * Create an OPTIONS request for the client
     *
     * @param string|array $uri Resource URI
     *
     * @return RequestInterface
     * @see    Guzzle\Http\ClientInterface::createRequest()
     */
    public function options($uri = null);

    /**
     * Sends a single request or an array of requests in parallel
     *
     * @param array $requests Request(s) to send
     *
     * @return array Returns the response(s)
     */
    public function send($requests);

    /**
     * Get the curl multi object to be used internally by the client for transferring requests.
     *
     * @return CurlMultiInterface
     */
    public function getCurlMulti();

    /**
     * Set the request factory to use with the client when creating requests
     *
     * @param RequestFactoryInterface $factory Request factory
     *
     * @return ClientInterface
     */
    public function setRequestFactory(RequestFactoryInterface $factory);
}
