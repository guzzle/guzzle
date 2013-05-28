<?php

namespace Guzzle\Http;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\StreamRequestFactoryInterface;
use Guzzle\Stream\PhpStreamRequestFactory;

/**
 * Simplified interface to Guzzle that does not require a class to be instantiated
 */
final class StaticClient
{
    /**
     * @var Client Guzzle client
     */
    private static $client;

    /**
     * Mount the client to a simpler class name for a specific client
     *
     * @param string          $className Class name to use to mount
     * @param ClientInterface $client    Client used to send requests
     */
    public static function mount($className = 'Guzzle', ClientInterface $client = null)
    {
        class_alias(__CLASS__, $className);
        if ($client) {
            self::$client = $client;
        }
    }

    /**
     * @param  string $method  HTTP request method (GET, POST, HEAD, DELETE, PUT, etc)
     * @param  string $url     URL of the request
     * @param  array  $options Options to use with the request. Available options are:
     *                         "headers": Associative array of headers
     *                         "body": Body of a request, including an EntityBody, string, or array when sending POST
     *                                 requests. Setting a body for a GET request will set where the response body is
     *                                 downloaded.
     *                         "allow_redirects": Set to false to disable redirects
     *                         "auth": Basic auth array where index 0 is the username and index 1 is the password
     *                         "query": Associative array of query string values to add to the request
     *                         "cookies": Associative array of cookies
     *                         "curl": Associative array of CURL options to add to the request
     *                         "events": Associative array mapping event names to callables
     *                         "stream": Set to true to retrieve a Guzzle\Stream\Stream object instead of a response
     *                         "plugins": Array of plugins to add to the request
     *
     * @return \Guzzle\Http\Message\Response|\Guzzle\Stream\Stream
     */
    public static function request($method, $url, $options = array())
    {
        if (!self::$client) {
            self::$client = new Client();
        }

        $request = self::$client->createRequest($method, $url);
        $returnValue = null;

        // Iterate over each key value pair and attempt to apply a config using function visitors
        foreach ($options as $key => $value) {
            $method = __CLASS__ . '::visit_' . $key;
            if (function_exists($method)) {
                $result = call_user_func($method, $request, $value);
                if ($result !== null) {
                    $returnValue = $result;
                }
            } else {
                die('aaa' . $method);
            }
        }

        return $returnValue ?: $request->send();
    }

    /**
     * Send a GET request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function get($url, $options = array())
    {
        return self::request('GET', $url, $options);
    }

    /**
     * Send a HEAD request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function head($url, $options = array())
    {
        return self::request('HEAD', $url, $options);
    }

    /**
     * Send a DELETE request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function delete($url, $options = array())
    {
        return self::request('DELETE', $url, $options);
    }

    /**
     * Send a POST request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function post($url, $options = array())
    {
        return self::request('POST', $url, $options);
    }

    /**
     * Send a PUT request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function put($url, $options = array())
    {
        return self::request('PUT', $url, $options);
    }

    /**
     * Send a PATCH request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function patch($url, $options = array())
    {
        return self::request('PATCH', $url, $options);
    }

    /**
     * Send an OPTIONS request
     *
     * @param string $url     URL of the request
     * @param array  $options Array of request options
     *
     * @return \Guzzle\Http\Message\Response
     * @see Guzzle::request for a list of available options
     */
    public static function options($url, $options = array())
    {
        return self::request('OPTIONS', $url, $options);
    }

    private static function visit_headers(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('headers value must be an array');
        }

        $request->addHeaders($value);
    }

    private static function visit_body(RequestInterface $request, $value)
    {
        if ($request instanceof EntityEnclosingRequestInterface) {
            $request->setBody($value);
        } else {
            throw new InvalidArgumentException('Attempting to set a body on a non-entity-enclosing request');
        }
    }

    private static function visit_allow_redirects(RequestInterface $request, $value)
    {
        if ($value === false) {
            $request->getParams()->set(RedirectPlugin::DISABLE, true);
        }
    }

    private static function visit_auth(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('auth value must be an array');
        }

        $request->setAuth($value[0], isset($value[1]) ? $value[1] : null);
    }

    private static function visit_query(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('query value must be an array');
        }

        $request->getQuery()->overwriteWith($value);
    }

    private static function visit_cookies(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('cookies value must be an array');
        }

        foreach ($value as $name => $v) {
            $request->addCookie($name, $v);
        }
    }

    private static function visit_curl(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('curl value must be an array');
        }

        $request->getCurlOptions()->overwriteWith($value);
    }

    private static function visit_events(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('events value must be an array');
        }

        foreach ($value as $name => $method) {
            $request->getEventDispatcher()->addListener($name, $method);
        }
    }

    private static function visit_stream(RequestInterface $request, $value)
    {
        if ($value instanceof StreamRequestFactoryInterface) {
            return $value->fromRequest($request);
        } elseif ($value == true) {
            $streamFactory = new PhpStreamRequestFactory();
            return $streamFactory->fromRequest($request);
        }
    }

    private static function visit_plugins(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('plugins value must be an array');
        }

        foreach ($value as $plugin) {
            $request->addSubscriber($plugin);
        }
    }
}
