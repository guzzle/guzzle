<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\HasEmitterTrait;
use Guzzle\Common\Collection;
use Guzzle\Http\Subscriber\PrepareRequestBody;
use Guzzle\Stream\StreamInterface;
use Guzzle\Url\Url;

/**
 * HTTP request class to send requests
 */
class Request implements RequestInterface
{
    use HasEmitterTrait, MessageTrait {
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
     *     - emitter: Event emitter to use with the request
     */
    public function __construct($method, $url, $headers = [], $body = null, array $options = [])
    {
        $this->setUrl($url);
        $this->method = strtoupper($method);
        $this->handleOptions($options);
        $this->transferOptions = new Collection($options);
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
        if ($this->emitter) {
            $this->emitter = clone $this->emitter;
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
     * Accepts and modifies the options provided to the request in the
     * constructor.
     *
     * Can be overridden in subclasses as necessary. Options that are not
     * removed from the passed array are set in the $transferOptions property
     * of the request.
     *
     * @param array $options Options array passed by reference.
     */
    protected function handleOptions(array &$options = [])
    {
        if (isset($options['protocol_version'])) {
            $this->protocolVersion = $options['protocol_version'];
        }

        // Use a custom emitter if one is specified, and remove it from
        // options that are exposed through getConfig()
        if (isset($options['emitter'])) {
            $this->emitter = $options['emitter'];
            unset($options['emitter']);
        }
    }

    /**
     * Adds a subscriber that ensures a request's body is prepared before sending
     */
    private function addPrepareEvent()
    {
        static $subscriber;
        if (!$subscriber) {
            $subscriber = new PrepareRequestBody();
        }

        $this->getEmitter()->addSubscriber($subscriber);
    }

    private function updateHostHeaderFromUrl()
    {
        $port = $this->url->getPort();
        $scheme = $this->url->getScheme();
        if ($host = $this->url->getHost()) {
            if (($port == 80 && $scheme == 'http') || ($port == 443 && $scheme == 'https')) {
                $this->setHeader('Host', $this->url->getHost());
            }  else {
                $this->setHeader('Host', $this->url->getHost() . ':' . $port);
            }
        }
    }
}
