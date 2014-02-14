<?php

namespace Guzzle;

use Guzzle\Http\Client;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Url\UriTemplate;

const VERSION = '4.0-dev';

/**
 * Send a custom request
 *
 * @param string $method  HTTP request method (GET, POST, HEAD, DELETE, PUT, etc)
 * @param string $url     URL of the request
 * @param array  $options Options to use with the request. See: Guzzle\Http\Message\RequestFactory::applyOptions()
 *
 * @return ResponseInterface
 */
function request($method, $url, array $options = [])
{
    static $client;
    if (!$client) {
        $client = new Client();
    }

    return $client->send($client->createRequest($method, $url, $options));
}

/**
 * Send a GET request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function get($url, array $options = [])
{
    return request('GET', $url, $options);
}

/**
 * Send a HEAD request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function head($url, array $options = [])
{
    return request('HEAD', $url, $options);
}

/**
 * Send a DELETE request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function delete($url, array $options = [])
{
    return request('DELETE', $url, $options);
}

/**
 * Send a POST request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function post($url, array $options = [])
{
    return request('POST', $url, $options);
}

/**
 * Send a PUT request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function put($url, array $options = [])
{
    return request('PUT', $url, $options);
}

/**
 * Send a PATCH request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function patch($url, array $options = [])
{
    return request('PATCH', $url, $options);
}

/**
 * Send an OPTIONS request
 *
 * @param string $url     URL of the request
 * @param array  $options Array of request options
 *
 * @return ResponseInterface
 */
function options($url, array $options = [])
{
    return request('OPTIONS', $url, $options);
}

/**
 * Expands a URI template
 *
 * @param string $template  URI template
 * @param array  $variables Template variables
 */
function uriTemplate($template, array $variables)
{
    if (function_exists('uri_template')) {
        return uri_template($template, $variables);
    }

    static $uriTemplate;
    if (!$uriTemplate) {
        $uriTemplate = new UriTemplate();
    }

    return $uriTemplate->expand($template, $variables);
}
