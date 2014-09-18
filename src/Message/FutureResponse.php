<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Ring\MagicFutureTrait;
use GuzzleHttp\Ring\Core;
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
 * @property ResponseInterface result
 */
class FutureResponse implements ResponseInterface, FutureInterface
{
    use MagicFutureTrait;

    public function getStatusCode()
    {
        return $this->result->getStatusCode();
    }

    public function getReasonPhrase()
    {
        return $this->result->getReasonPhrase();
    }

    public function getEffectiveUrl()
    {
        return $this->result->getEffectiveUrl();
    }

    public function setEffectiveUrl($url)
    {
        $this->result->setEffectiveUrl($url);
    }

    public function json(array $config = [])
    {
        return $this->result->json($config);
    }

    public function xml(array $config = [])
    {
        return $this->result->xml($config);
    }

    public function __toString()
    {
        return $this->result->__toString();
    }

    public function getProtocolVersion()
    {
        return $this->result->getProtocolVersion();
    }

    public function setBody(StreamInterface $body = null)
    {
        $this->result->setBody($body);
    }

    public function getBody()
    {
        return $this->result->getBody();
    }

    public function getHeaders()
    {
        return $this->result->getHeaders();
    }

    public function getHeader($header)
    {
        return $this->result->getHeader($header);
    }

    public function getHeaderLines($header)
    {
        return $this->result->getHeaderLines($header);
    }

    public function hasHeader($header)
    {
        return $this->result->hasHeader($header);
    }

    public function removeHeader($header)
    {
        $this->result->removeHeader($header);
    }

    public function addHeader($header, $value)
    {
        $this->result->addHeader($header, $value);
    }

    public function addHeaders(array $headers)
    {
        $this->result->addHeaders($headers);
    }

    public function setHeader($header, $value)
    {
        $this->result->setHeader($header, $value);
    }

    public function setHeaders(array $headers)
    {
        $this->result->setHeaders($headers);
    }

    /** @internal */
    protected function processResult($result)
    {
        if (!$result instanceof ResponseInterface) {
            throw new \RuntimeException('Future did not return a valid '
                . 'response. Found ' . Core::describeType($result));
        }

        return $result;
    }
}
