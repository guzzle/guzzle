<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Ring\BaseFutureTrait;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Exception\CancelledFutureAccessException;
use GuzzleHttp\Ring\FutureInterface;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Represents a response that has not been fulfilled.
 *
 * When created, you must provide a function that is used to dereference the
 * future result and return it's value. The function has no arguments and MUST
 * return an instance of a {@see GuzzleHttp\Message\ResponseInterface} object.
 *
 * You can optionally provide a function in the constructor that can be used to
 * cancel the future from completing if possible. This function has no
 * arguments and returns a boolean value representing whether or not the
 * response could be cancelled.
 *
 * @property ResponseInterface response
 */
class FutureResponse implements ResponseInterface, FutureInterface
{
    use BaseFutureTrait;

    public function deref()
    {
        return $this->response;
    }

    public function getStatusCode()
    {
        return $this->response->getStatusCode();
    }

    public function getReasonPhrase()
    {
        return $this->response->getReasonPhrase();
    }

    public function getEffectiveUrl()
    {
        return $this->response->getEffectiveUrl();
    }

    public function setEffectiveUrl($url)
    {
        $this->response->setEffectiveUrl($url);
    }

    public function json(array $config = [])
    {
        return $this->response->json($config);
    }

    public function xml(array $config = [])
    {
        return $this->response->xml($config);
    }

    public function __toString()
    {
        return $this->response->__toString();
    }

    public function getProtocolVersion()
    {
        return $this->response->getProtocolVersion();
    }

    public function setBody(StreamInterface $body = null)
    {
        $this->response->setBody($body);
    }

    public function getBody()
    {
        return $this->response->getBody();
    }

    public function getHeaders()
    {
        return $this->response->getHeaders();
    }

    public function getHeader($header)
    {
        return $this->response->getHeader($header);
    }

    public function getHeaderLines($header)
    {
        return $this->response->getHeaderLines($header);
    }

    public function hasHeader($header)
    {
        return $this->response->hasHeader($header);
    }

    public function removeHeader($header)
    {
        $this->response->removeHeader($header);
    }

    public function addHeader($header, $value)
    {
        $this->response->addHeader($header, $value);
    }

    public function addHeaders(array $headers)
    {
        $this->response->addHeaders($headers);
    }

    public function setHeader($header, $value)
    {
        $this->response->setHeader($header, $value);
    }

    public function setHeaders(array $headers)
    {
        $this->response->setHeaders($headers);
    }

    /** @internal */
    public function __get($name)
    {
        if ($name !== 'response') {
            throw new \RuntimeException('Unknown property: ' . $name);
        } elseif ($this->isCancelled) {
            throw new CancelledFutureAccessException('You are attempting '
                . 'to access a future that has been cancelled.');
        }

        $dereffn = $this->dereffn;
        $this->dereffn = $this->cancelfn = null;
        $this->response = $dereffn();

        if (!$this->response instanceof ResponseInterface) {
            throw new \RuntimeException('Future did not return a valid '
                . 'response. Found ' . Core::describeType($this->response));
        }

        return $this->response;
    }
}
