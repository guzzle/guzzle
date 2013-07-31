<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\HasDispatcher;
use Guzzle\Common\Collection;
use Guzzle\Http\Header\HeaderInterface;
use Guzzle\Url\Url;

/**
 * HTTP request class to send requests
 */
class Request extends AbstractMessage implements RequestInterface
{
    use HasDispatcher;

    /** @var Url HTTP Url */
    protected $url;

    /** @var string HTTP method (GET, PUT, POST, DELETE, HEAD, OPTIONS, TRACE) */
    protected $method;

    /** @var Collection Transfer options */
    protected $transferOptions;

    /**
     * @param string           $method  HTTP method
     * @param string|Url       $url     HTTP URL to connect to. The URI scheme, host header, and URI are parsed from the
     *                                  full URL. If query string parameters are present they will be parsed as well.
     * @param array|Collection $headers HTTP headers
     * @param mixed            $body    Body to send with the request
     */
    public function __construct($method, $url, $headers = array(), $body = null)
    {
        parent::__construct();
        $this->method = strtoupper($method);
        $this->transferOptions = new Collection();
        $this->setUrl($url);

        if ($body) {
            $this->setBody($body);
        }

        if ($headers) {
            // Special handling for multi-value headers
            foreach ($headers as $key => $value) {
                // Deal with collisions with Host and Authorization
                if ($key == 'host' || $key == 'Host') {
                    $this->setHeader($key, $value);
                } elseif ($value instanceof HeaderInterface) {
                    $this->addHeader($key, $value);
                } else {
                    foreach ((array) $value as $v) {
                        $this->addHeader($key, $v);
                    }
                }
            }
        }
    }

    public function __clone()
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher = clone $this->eventDispatcher;
        }
        $this->transferOptions = clone $this->transferOptions;
        $this->url = clone $this->url;
        $this->headers = clone $this->headers;
    }

    public function serialize()
    {
        return json_encode(array(
            'method'  => $this->method,
            'url'     => $this->getUrl(),
            'headers' => $this->headers->toArray(),
            'body'    => (string) $this->body
        ));
    }

    public function unserialize($serialize)
    {
        $data = json_decode($serialize, true);
        $this->__construct($data['method'], $data['url'], $data['headers'], $data['body']);
    }

    public function getStartLine()
    {
        $protocolVersion = $this->protocolVersion ?: '1.1';

        return trim($this->method . ' ' . $this->getResource()) . ' '
            . strtoupper(str_replace('https', 'http', $this->url->getScheme()))
            . '/' . $protocolVersion;
    }

    /**
     * {@inheritdoc}
     * @throws \LengthException If the content-length cannot be determined and using HTTP/1.0
     */
    public function setBody($body, $contentType = null)
    {
        parent::setBody($body, $contentType);

        // Always add the Expect 100-Continue header if the body cannot be rewound
        if (!$this->body->isSeekable()) {
            $this->setHeader('Expect', '100-Continue');
        }

        // Set the Content-Length header if it can be determined
        $size = $this->body->getSize();
        if ($size !== null && $size !== false) {
            $this->setHeader('Content-Length', $size);
        } elseif (!$this->hasHeader('Content-Length')) {
            if ('1.1' == $this->protocolVersion) {
                $this->setHeader('Transfer-Encoding', 'chunked');
            } else {
                throw new \LengthException(
                    'Cannot determine Content-Length and cannot use chunked Transfer-Encoding when using HTTP/1.0'
                );
            }
        }

        return $this;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function setUrl($url)
    {
        $this->url = $url instanceof Url ? $url : Url::fromString($url);
        // Update the port and host header
        $this->setPort($this->url->getPort());

        return $this;
    }

    public function getUrl()
    {
        return (string) $this->url;
    }

    public function getQuery()
    {
        return $this->url->getQuery();
    }

    public function setMethod($method)
    {
        $this->method = strtoupper($method);

        return $this;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function getScheme()
    {
        return $this->url->getScheme();
    }

    public function setScheme($scheme)
    {
        $this->url->setScheme($scheme);

        return $this;
    }

    public function getHost()
    {
        return $this->url->getHost();
    }

    public function setHost($host)
    {
        $this->url->setHost($host);
        $this->setPort($this->url->getPort());

        return $this;
    }

    public function getPath()
    {
        return '/' . ltrim($this->url->getPath(), '/');
    }

    public function setPath($path)
    {
        $this->url->setPath($path);

        return $this;
    }

    public function getPort()
    {
        return $this->url->getPort();
    }

    public function setPort($port)
    {
        $this->url->setPort($port);

        // Include the port in the Host header if it is not the default port for the scheme of the URL
        $scheme = $this->url->getScheme();
        if (($scheme == 'http' && $port != 80) || ($scheme == 'https' && $port != 443)) {
            $this->headers['host'] = $this->headerFactory->createHeader('Host', $this->url->getHost() . ':' . $port);
        } else {
            $this->headers['host'] = $this->headerFactory->createHeader('Host', $this->url->getHost());
        }

        return $this;
    }

    public function getResource()
    {
        $resource = $this->getPath();
        if ($query = (string) $this->url->getQuery()) {
            $resource .= '?' . $query;
        }

        return $resource;
    }

    public function getTransferOptions()
    {
        return $this->transferOptions;
    }
}
