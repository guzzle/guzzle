<?php

namespace GuzzleHttp;

use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\UriTemplate;

/**
 * Send a custom request
 *
 * @param string $method  HTTP request method
 * @param string $url     URL of the request
 * @param array  $options Options to use with the request.
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
 *
 * @return string
 */
function uri_template($template, array $variables)
{
    if (function_exists('\\uri_template')) {
        return \uri_template($template, $variables);
    }

    static $uriTemplate;
    if (!$uriTemplate) {
        $uriTemplate = new UriTemplate();
    }

    return $uriTemplate->expand($template, $variables);
}

/**
 * @internal
 */
function deprecation_proxy($object, $name, $arguments, $map)
{
    if (!isset($map[$name])) {
        throw new \BadMethodCallException('Unknown method, ' . $name);
    }

    $message = sprintf('%s is deprecated and will be removed in a future '
        . 'version. Update your code to use the equivalent %s method '
        . 'instead to avoid breaking changes when this shim is removed.',
        get_class($object) . '::' . $name . '()',
        get_class($object) . '::' . $map[$name] . '()'
    );

    trigger_error($message, E_USER_DEPRECATED);

    return call_user_func_array([$object, $map[$name]], $arguments);
}
