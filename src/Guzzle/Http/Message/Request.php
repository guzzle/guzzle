<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\HasDispatcherTrait;
use Guzzle\Common\Collection;
use Guzzle\Stream\StreamInterface;
use Guzzle\Url\Url;

/**
 * HTTP request class to send requests
 */
class Request implements RequestInterface
{
    use HasDispatcherTrait, MessageTrait {
        MessageTrait::setBody as applyBody;
    }

    /** @var Url HTTP Url */
    private $url;

    /** @var string HTTP method (GET, PUT, POST, DELETE, HEAD, OPTIONS, TRACE) */
    private $method;

    /** @var Collection Transfer options */
    private $transferOptions;

    /**
     * @param string           $method  HTTP method
     * @param string|Url       $url     HTTP URL to connect to. The URI scheme, host header, and URI are parsed from the
     *                                  full URL. If query string parameters are present they will be parsed as well.
     * @param array|Collection $headers HTTP headers
     * @param mixed            $body    Body to send with the request
     * @param array            $options Array of options to use with the request
     *                                  - header_factory: Header factory to use with the message
     *                                  - event_dispatcher: Event dispatcher to use with the request
     */
    public function __construct($method, $url, $headers = [], $body = null, array $options = [])
    {
        $this->initializeMessage($options);
        $this->method = strtoupper($method);
        $this->transferOptions = new Collection();
        $this->setUrl($url);

        if (isset($options['event_dispatcher'])) {
            $this->eventDispatcher = $options['event_dispatcher'];
        }

        $this->addPrepareEvent();

        if ($body) {
            $this->setBody($body);
        }

        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }
    }

    public function __clone()
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher = clone $this->eventDispatcher;
        }
        $this->transferOptions = clone $this->transferOptions;
        $this->url = clone $this->url;
        $this->headers = array_map(function ($header) {
            return clone $header;
        }, $this->headers);
    }

    public function __toString()
    {
        $result = trim($this->method . ' ' . $this->getResource()) . ' HTTP/' . $this->getProtocolVersion();
        foreach ($this->getHeaders() as $name => $value) {
            $result .= "\r\n{$name}: {$value}";
        }

        $result .= "\r\n\r\n";

        if ($this->body) {
            $this->body->seek(0);
            $result .= $this->body;
        }

        return  $result;
    }

    public function setBody(StreamInterface $body = null)
    {
        $this->applyBody($body);

        // Use chunked Transfer-Encoding if there is no content-length header
        if ($body !== null && !$this->hasHeader('Content-Length') && '1.1' == $this->getProtocolVersion()) {
            $this->setHeader('Transfer-Encoding', 'chunked');
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
        $this->updateHostHeaderFromUrl();

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
        $this->updateHostHeaderFromUrl();

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

    public function getResource()
    {
        $resource = $this->getPath();
        if ($query = (string) $this->url->getQuery()) {
            $resource .= '?' . $query;
        }

        return $resource;
    }

    public function getConfig()
    {
        return $this->transferOptions;
    }

    /**
     * Adds a subscriber that ensures a request's body is prepared before sending
     */
    private function addPrepareEvent()
    {
        static $subscriber;
        if (!$subscriber) {
            $subscriber = new PrepareRequestBodySubscriber();
        }

        $this->getEventDispatcher()->addSubscriber($subscriber);
    }

    private function updateHostHeaderFromUrl()
    {
        // Include the port in the Host header if it is not the default port for the scheme of the URL
        if ($port = $this->url->getPort()) {
            $this->setHeader('Host', $this->url->getHost() . ':' . $port);
        } else {
            $this->setHeader('Host', $this->url->getHost());
        }
    }
}
