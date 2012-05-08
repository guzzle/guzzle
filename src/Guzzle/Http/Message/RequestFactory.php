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
 */
class RequestFactory implements RequestFactoryInterface
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
        'range', 'referer', 'te', 'transfer-encoding', 'upgrade', 'user-agent',
        'via', 'warning'
    );

    /**
     * @var RequestFactory Singleton instance of the default request factory
     */
    protected static $instance;

    /**
     * @var string Class to instantiate for GET, HEAD, and DELETE requests
     */
    protected $requestClass = 'Guzzle\\Http\\Message\\Request';

    /**
     * @var string Class to instantiate for POST and PUT requests
     */
    protected $entityEnclosingRequestClass = 'Guzzle\\Http\\Message\\EntityEnclosingRequest';

    /**
     * Get a cached instance of the default request factory
     *
     * @return RequestFactory
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!static::$instance) {
            static::$instance = new static();
        }
        // @codeCoverageIgnoreEnd

        return static::$instance;
    }

    /**
     * {@inheritdoc}
     */
    public function parseMessage($message)
    {
        if (!$message) {
            return false;
        }

        $headers = new Collection();
        $scheme = $host = $body = $method = $user = $pass = $query = $port = $version = $protocol = '';
        $path = '/';

        // Inspired by https://github.com/kriswallsmith/Buzz/blob/message-interfaces/lib/Buzz/Message/Parser/Parser.php#L16
        $lines = preg_split('/(\\r?\\n)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0, $c = count($lines); $i < $c; $i += 2) {

            $line = $lines[$i];

            // If two line breaks were encountered, then this is the body
            if (empty($line)) {
                $body = implode('', array_slice($lines, $i + 2));
                break;
            }

            // Parse message headers
            if (!$method) {
                list($method, $path, $proto) = explode(' ', $line);
                $method = strtoupper($method);
                list($protocol, $version) = explode('/', strtoupper($proto));
                $scheme = 'http';
            } else if (strpos($line, ':')) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                // Normalize standard HTTP headers
                if (in_array(strtolower($key), static::$requestHeaders)) {
                    $key = str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)));
                }
                // Headers are case insensitive
                $headers->add($key, trim($value));
            }
        }

        // Check for the Host header
        if (isset($headers['Host'])) {
            $host = $headers['Host'];
        }

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
            'headers' => $headers->getAll(),
            'body' => $body
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fromMessage($message)
    {
        $parsed = $this->parseMessage($message);

        if (!$parsed) {
            return false;
        }

        $request = $this->fromParts($parsed['method'], $parsed['parts'],
            $parsed['headers'], $parsed['body'], $parsed['protocol'],
            $parsed['protocol_version']);

        // EntityEnclosingRequest adds an "Expect: 100-Continue" header when
        // using a raw request body for PUT or POST requests. This factory
        // method should accurately reflect the message, so here we are
        // removing the Expect header if one was not supplied in the message.
        if (!isset($parsed['headers']['Expect'])) {
            $request->removeHeader('Expect');
        }

        return $request;
    }

    /**
     * {@inheritdoc}
     */
    public function fromParts($method, array $parts, $headers = null, $body = null, $protocol = 'HTTP', $protocolVersion = '1.1')
    {
        return $this->create($method, Url::buildUrl($parts, true), $headers, $body)
                    ->setProtocolVersion($protocolVersion);
    }

    /**
     * {@inheritdoc}
     */
    public function create($method, $url, $headers = null, $body = null)
    {
        if ($method != 'POST' && $method != 'PUT' && $method != 'PATCH') {
            $c = $this->requestClass;
            $request = new $c($method, $url, $headers);
            if ($body) {
                $request->setResponseBody(EntityBody::factory($body));
            }
        } else {
            $c = $this->entityEnclosingRequestClass;
            $request = new $c($method, $url, $headers);

            if ($body) {
                if ($method == 'POST' && (is_array($body) || $body instanceof Collection)) {
                    $request->addPostFields($body);
                } else if (is_resource($body) || $body instanceof EntityBody) {
                    $request->setBody($body, (string) $request->getHeader('Content-Type'));
                } else {
                    $request->setBody((string) $body, (string) $request->getHeader('Content-Type'));
                }
            }

            // Fix chunked transfers based on the passed headers
            if (isset($headers['Transfer-Encoding']) && $headers['Transfer-Encoding'] == 'chunked') {
                $request->removeHeader('Content-Length')
                        ->setHeader('Transfer-Encoding', 'chunked');
            }
        }

        return $request;
    }
}
