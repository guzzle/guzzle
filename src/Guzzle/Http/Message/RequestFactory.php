<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;

/**
 * Default HTTP request factory used to create the default
 * Guzzle\Http\Message\Request and Guzzle\Http\Message\EntityEnclosingRequest
 * objects.
 */
class RequestFactory implements RequestFactoryInterface
{
    /**
     * @var RequestFactory Singleton instance of the default request factory
     */
    protected static $instance;

    /**
     * @var string Class to instantiate for requests with no body
     */
    protected $requestClass = 'Guzzle\\Http\\Message\\Request';

    /**
     * @var string Class to instantiate for requests with a body
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
    public function fromMessage($message)
    {
        $parsed = ParserRegistry::get('message')->parseRequest($message);

        if (!$parsed) {
            return false;
        }

        $request = $this->fromParts($parsed['method'], $parsed['request_url'],
            $parsed['headers'], $parsed['body'], $parsed['protocol'],
            $parsed['version']);

        // EntityEnclosingRequest adds an "Expect: 100-Continue" header when
        // using a raw request body for PUT or POST requests. This factory
        // method should accurately reflect the message, so here we are
        // removing the Expect header if one was not supplied in the message.
        if (!isset($parsed['headers']['Expect']) && !isset($parsed['headers']['expect'])) {
            $request->removeHeader('Expect');
        }

        return $request;
    }

    /**
     * {@inheritdoc}
     */
    public function fromParts(
        $method,
        array $urlParts,
        $headers = null,
        $body = null,
        $protocol = 'HTTP',
        $protocolVersion = '1.1'
    ) {
        return $this->create($method, Url::buildUrl($urlParts, true), $headers, $body)
                    ->setProtocolVersion($protocolVersion);
    }

    /**
     * {@inheritdoc}
     */
    public function create($method, $url, $headers = null, $body = null)
    {
        $method = strtoupper($method);

        if ($method == 'GET' || $method == 'HEAD' || $method == 'TRACE' || $method == 'OPTIONS') {
            $c = $this->requestClass;
            $request = new $c($method, $url, $headers);
            if ($body) {
                // The body is where the response body will be stored
                $request->setResponseBody(EntityBody::factory($body));
            }
            return $request;
        }

        $c = $this->entityEnclosingRequestClass;
        $request = new $c($method, $url, $headers);

        if ($body) {

            $isChunked = (string) $request->getHeader('Transfer-Encoding') == 'chunked';

            if ($method == 'POST' && (is_array($body) || $body instanceof Collection)) {

                // Normalize PHP style cURL uploads with a leading '@' symbol
                $files = array();
                foreach ($body as $key => $value) {
                    if (is_string($value) && strpos($value, '@') === 0) {
                        $files[$key] = $value;
                        unset($body[$key]);
                    }
                }

                // Add the fields if they are still present and not all files
                if (count($body) > 0) {
                    $request->addPostFields($body);
                }
                // Add any files that were prefixed with '@'
                if (!empty($files)) {
                    $request->addPostFiles($files);
                }

                if ($isChunked) {
                    $request->setHeader('Transfer-Encoding', 'chunked');
                }

            } elseif (is_resource($body) || $body instanceof EntityBody) {
                $request->setBody($body, (string) $request->getHeader('Content-Type'), $isChunked);
            } else {
                $request->setBody((string) $body, (string) $request->getHeader('Content-Type'), $isChunked);
            }
        }

        return $request;
    }
}
