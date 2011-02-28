<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Url;

/**
 * Default HTTP request factory.
 *
 * <code>
 * $factory = new RequestFactory();
 * $request = $factory->newRequest('GET', 'http://www.google.com/');
 * $response = $request->send();
 * echo $response;
 * </code>
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class RequestFactory
{
    /**
     * @var RequestFactory Singleton instance
     */
    private static $instance;

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
     * Singleton method to get a single instance of the default RequestFactory.
     *
     * Because the default request factory will be most commonly used, it is
     * recommended to get the singleton instance of the RequestFactory when
     * creating standard HTTP requests.
     *
     * @return RequestFactory
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
        // @codeCoverageIgnoreEnd
    }

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
    public function parseMessage($message)
    {
        if (!$message) {
            return false;
        }

        // Normalize new lines in the message
        $message = preg_replace("/([^\r])(\n)\b/", "$1\r\n", $message);
        $parts = explode("\r\n\r\n", $message, 2);
        $headers = array();
        $scheme = $host = $method = $user = $pass = $query = $port = '';
        $path = '/';

        // Parse each line in the message
        foreach (explode("\r\n", $parts[0]) as $line) {
            if (preg_match('/^(GET|POST|PUT|HEAD|DELETE|TRACE|OPTIONS)\s+\/*.+\s+[A-Za-z]+\/[0-9]\.[0-9]\s*$/i', $line)) {
                list($method, $path, $protocol) = array_map('trim', explode(' ', $line, 3));
                list($protocol, $version) = explode('/', $protocol);
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
            'method' => strtoupper($method),
            'protocol' => strtoupper($protocol),
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
    public function createFromMessage($message)
    {
        $parsed = $this->parseMessage($message);

        if (!$parsed) {
            return false;
        }

        return $this->createFromParts(
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
    public function createFromParts($method, array $parts, $headers = null, $body = null, $protocol = 'HTTP', $protocolVersion = '1.1')
    {
        return $this->newRequest($method, Url::buildUrl($parts, true), $headers, $body)
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
    public function newRequest($method, $url, $headers = null, $body = null)
    {
        if ($method != 'POST' && $method != 'PUT') {
            $request = new Request($method, $url, $headers);
        } else {
            $request = new EntityEnclosingRequest($method, $url, $headers);

            if ($body) {

                if ($method == 'POST') {
                    if (is_array($body)) {
                        $request->addPostFields(new QueryString($body));
                    } if ($body instanceof Collection) {
                        $request->addPostFields($body->getAll());
                    }
                }
                
                if ($body instanceof EntityBody) {
                    $request->setBody($body);
                } else if (is_string($body)) {
                    $request->setBody(EntityBody::factory($body));
                }
            }
        }

        return $request;
    }
}