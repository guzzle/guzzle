<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Url;

/**
 * Default HTTP request factory used to create the default
 * Guzzle\Http\Message\Request and Guzzle\Http\Message\EntityEnclosingRequest
 * objects.
 *
 * If you need to extend the Request or EntityEnclosingRequest classes, then
 * this default factory implementation will not work for your client, though
 * you can extend this class with your custom factory.
 *
 * <code>
 * $request = RequestFactory::get('http://www.google.com/');
 * $response = $request->send();
 * </code>
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class RequestFactory
{
    /**
     * @var Standard request headers
     */
    protected static $requestHeaders = array(
        'accept', 'accept-charset', 'accept-encoding', 'accept-language',
        'authorization', 'cache-control', 'connection', 'cookie',
        'content-length', 'content-type', 'date', 'expect', 'from', 'host',
        'if-match', 'if-modified-since', 'if-none-match', 'if-range',
        'if-unmodified-since', 'max-forwards', 'pragma', 'proxy-authorization',
        'range', 'referer', 'te', 'upgrade', 'user-agent', 'via', 'warning'
    );

    /**
     * @var string Class to instantiate for GET, HEAD, and DELETE requests
     */
    protected static $requestClass = 'Guzzle\\Http\\Message\\Request';

    /**
     * @var string Class to instantiate for POST and PUT requests
     */
    protected static $entityEnclosingRequestClass = 'Guzzle\\Http\\Message\\EntityEnclosingRequest';
    
    /**
     * Parse an HTTP message and return an array of request information
     *
     * @param string $message HTTP request message to parse
     *
     * @return array|bool Returns FALSE on failure or an array containing the
     *      following key value pairs:
     *
     *      # method: HTTP request method (e.g. GET, HEAD, POST, PUT, etc)
     *      # protocol - HTTP protocol (e.g. HTTP)
     *      # protocol_version: HTTP protocol version (e.g. 1.1)
     *      # parts: array of request parts as seen in parse_url()
     *      # headers: associative array of request headers
     *      # body: string containing the request body
     */
    public static function parseMessage($message)
    {
        if (!$message) {
            return false;
        }

        // Normalize new lines in the message
        $message = preg_replace("/([^\r])(\n)\b/", "$1\r\n", $message);
        $parts = explode("\r\n\r\n", $message, 2);
        $headers = array();
        $scheme = $host = $method = $user = $pass = $query = $port = $version = $protocol = '';
        $path = '/';

        // Parse each line in the message
        foreach (explode("\r\n", $parts[0]) as $line) {
            $matches = array();
            if (preg_match('#^(?P<method>GET|POST|PUT|HEAD|DELETE|TRACE|OPTIONS)\s+(?P<path>/.*)\s+(?P<protocol>\w+)/(?P<version>\d\.\d)\s*$#i', $line, $matches)) {
                $method = strtoupper($matches['method']);
                $protocol = strtoupper($matches['protocol']);
                $path = $matches['path'];
                $version = $matches['version'];
                $scheme = 'http';
            } else if (strpos($line, ':')) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                // Normalize standard HTTP headers
                if (in_array(strtolower($key), self::$requestHeaders)) {
                    $key = trim(str_replace(' ', '-', ucwords(str_replace('-', ' ', $key))));
                }
                $headers[$key] = trim($value);
            }
        }

        // Check if a body is present in the message
        $body = (isset($parts[1])) ? $parts[1] : null;

        // Check for the Host header
        $host = isset($headers['Host']) ? $headers['Host'] : '';

        if (strpos($host, ':')) {
            list($host, $port) = array_map('trim', explode(':', $host));
            if ($port == 443) {
                $scheme = 'https';
            }
        } else {
            $port = '';
        }

        // Check for basic authorization
        $auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
        
        if ($auth) {
            list($type, $data) = explode(' ', $auth);
            if (strtolower($type) == 'basic') {
                $data = base64_decode($data);
                list($user, $pass) = explode(':', $data);
            }
        }

        // Check if a query is present
        $qpos = strpos($path, '?');
        if ($qpos) {
            $query = substr($path, $qpos);
            $path = substr($path, 0, $qpos);
        }

        return array(
            'method' => $method,
            'protocol' => $protocol,
            'protocol_version' => $version,
            'parts' => array(
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'pass' => $pass,
                'path' => $path,
                'query' => $query
            ),
            'headers' => $headers,
            'body' => $body
        );
    }

    /**
     * Create a new request based on an HTTP message
     *
     * @param string $message HTTP message as a string
     *
     * @return RequestInterface
     */
    public static function fromMessage($message)
    {
        $parsed = self::parseMessage($message);

        if (!$parsed) {
            return false;
        }

        return self::fromParts(
            $parsed['method'],
            $parsed['parts'],
            $parsed['headers'],
            $parsed['body'],
            $parsed['protocol'],
            $parsed['protocol_version']
        );
    }

    /**
     * Create a request from URL parts as returned from parse_url()
     *
     * @param string $method HTTP method (GET, POST, PUT, HEAD, DELETE, etc)
     *
     * @param array $parts URL parts containing the same keys as parse_url()
     *     # scheme - e.g. http
     *     # host - e.g. www.guzzle-project.com
     *     # port - e.g. 80
     *     # user - e.g. michael
     *     # pass - e.g. rocks
     *     # path - e.g. / OR /index.html
     *     # query - after the question mark ?
     *
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body Body to send in the request
     * @param string $protocol (optional) Protocol (HTTP, SPYDY, etc)
     * @param string $protocolVersion (optional) 1.0, 1.1, etc
     *
     * @return RequestInterface
     */
    public static function fromParts($method, array $parts, $headers = null, $body = null, $protocol = 'HTTP', $protocolVersion = '1.1')
    {
        return self::create($method, Url::buildUrl($parts, true), $headers, $body)
                   ->setProtocolVersion($protocolVersion);
    }

    /**
     * Create a new request based on the HTTP method
     *
     * @param string $method HTTP method (GET, POST, PUT, HEAD, DELETE, etc)
     * @param string $url HTTP URL to connect to.  The URI scheme, host header,
     *      and URI are parsed from the full URL.  If query string parameters
     *      are present they will be parsed as well.
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body Body to send in the request
     *
     * @return RequestInterface
     */
    public static function create($method, $url, $headers = null, $body = null)
    {
        if ($method != 'POST' && $method != 'PUT') {
            $c = static::$requestClass;
            $request = new $c($method, $url, $headers);
        } else {
            $c = static::$entityEnclosingRequestClass;
            $request = new $c($method, $url, $headers);

            if ($body) {
                if ($method == 'POST' && (is_array($body) || $body instanceof Collection)) {
                    $request->addPostFields($body);
                } else if (is_resource($body) || $body instanceof EntityBody) {
                    $request->setBody($body);
                } else {
                    $request->setBody((string) $body);
                }
            }
        }

        return $request;
    }

    /**
     * Create a new GET request
     *
     * @param string $url URL of the GET request
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body (optional) Where to store
     *      the response entity body
     *
     * @return Request
     */
    public static function get($url, $headers = null, $body = null)
    {
        $request = self::create(RequestInterface::GET, $url, $headers);
        if ($body) {
            $request->setResponseBody($body);
        }

        return $request;
    }

    /**
     * Create a new HEAD request
     *
     * @param string $url URL of the HEAD request
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return Request
     */
    public static function head($url, $headers = null)
    {
        return self::create(RequestInterface::HEAD, $url, $headers);
    }

    /**
     * Create a new DELETE request
     *
     * @param string $url URL of the DELETE request
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return Request
     */
    public static function delete($url, $headers = null)
    {
        return self::create(RequestInterface::DELETE, $url, $headers);
    }

    /**
     * Create a new POST request
     *
     * @param string $url URL of the POST request
     * @param array|Collection $headers (optional) HTTP headers
     * @param array|Collection $postFields (optional) Associative array of POST
     *      fields to send in the body of the request.  Prefix a value in the
     *      array with the @ symbol reference a file.
     *
     * @return EntityEnclosingRequest
     */
    public static function post($url, $headers = null, $postFields = null)
    {
        return self::create(RequestInterface::POST, $url, $headers, $postFields);
    }

    /**
     * Create a new PUT request
     *
     * @param string $url URL of the PUT request
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body Body to send in the request
     *
     * @return EntityEnclosingRequest
     */
    public static function put($url, $headers = null, $body = null)
    {
        return self::create(RequestInterface::PUT, $url, $headers, $body);
    }

    /**
     * Create a new OPTIONS request
     *
     * @param string $url URL of the OPTIONS request
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return Request
     */
    public static function options($url, $headers = null, $body = null)
    {
        return self::create(RequestInterface::OPTIONS, $url, $headers, $body);
    }
}