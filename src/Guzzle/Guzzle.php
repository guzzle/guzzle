<?php

use Guzzle\Http\Client;
use Guzzle\Http\RedirectPlugin;
use Guzzle\Stream\StreamRequestFactoryInterface;
use Guzzle\Stream\PhpStreamRequestFactory;

/**
 * Simplified interface to Guzzle that does not require a class to be instantiated
 */
final class Guzzle
{
    /**
     * @var Client Guzzle client
     */
    private static $client;

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

        // Extract parameters from the config array
        $headers = $body = $curl = $auth = $events = $plugins = $allow_redirects = $cookies = $query = $stream = null;
        extract($options, EXTR_IF_EXISTS);

        $request = self::$client->createRequest($method, $url, $headers, $body);

        if ($allow_redirects === false) {
            $request->getParams()->set(RedirectPlugin::DISABLE, true);
        }

        if (is_array($cookies)) {
            foreach ($cookies as $name => $value) {
                $request->addCookie($name, $value);
            }
        }

        if (is_array($query)) {
            $request->getQuery()->overwriteWith($query);
        }

        if (is_array($curl)) {
            $request->getCurlOptions()->overwriteWith($curl);
        }

        if (is_array($auth)) {
            $request->setAuth($auth[0], isset($auth[1]) ? $auth[1] : null);
        }

        if (is_array($events)) {
            foreach ($events as $name => $method) {
                $request->getEventDispatcher()->addListener($name, $method);
            }
        }

        if (is_array($plugins)) {
            foreach ($plugins as $plugin) {
                $request->addSubscriber($plugin);
            }
        }

        if (!$stream) {
            return $request->send();
        } elseif ($stream instanceof StreamRequestFactoryInterface) {
            return $stream->fromRequest($request);
        } else {
            $streamFactory = new PhpStreamRequestFactory();
            return $streamFactory->fromRequest($request);
        }
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
}
