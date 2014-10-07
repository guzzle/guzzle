<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Ring\Future\MagicFutureTrait;
use GuzzleHttp\Ring\Future\FutureInterface;
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
 * @property ResponseInterface $_value
 */
class FutureResponse implements ResponseInterface, FutureInterface
{
    use MagicFutureTrait;

    /**
     * Returns a FutureResponse that wraps another future.
     *
     * @param FutureInterface $future      Future to wrap with a new future
     * @param callable        $onFulfilled Invoked when the future fulfilled
     * @param callable        $onRejected  Invoked when the future rejected
     * @param callable        $onProgress  Invoked when the future progresses
     *
     * @return FutureResponse
     */
    public static function proxy(
        FutureInterface $future,
        callable $onFulfilled = null,
        callable $onRejected = null,
        callable $onProgress = null
    ) {
        return new FutureResponse(
            $future->then($onFulfilled, $onRejected, $onProgress),
            [$future, 'wait'],
            [$future, 'cancel']
        );
    }

    public function getStatusCode()
    {
        return $this->_value->getStatusCode();
    }

    public function setStatusCode($code)
    {
        $this->_value->setStatusCode($code);
    }

    public function getReasonPhrase()
    {
        return $this->_value->getReasonPhrase();
    }

    public function setReasonPhrase($phrase)
    {
        $this->_value->setReasonPhrase($phrase);
    }

    public function getEffectiveUrl()
    {
        return $this->_value->getEffectiveUrl();
    }

    public function setEffectiveUrl($url)
    {
        $this->_value->setEffectiveUrl($url);
    }

    public function json(array $config = [])
    {
        return $this->_value->json($config);
    }

    public function xml(array $config = [])
    {
        return $this->_value->xml($config);
    }

    public function __toString()
    {
        try {
            return $this->_value->__toString();
        } catch (\Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return '';
        }
    }

    public function getProtocolVersion()
    {
        return $this->_value->getProtocolVersion();
    }

    public function setBody(StreamInterface $body = null)
    {
        $this->_value->setBody($body);
    }

    public function getBody()
    {
        return $this->_value->getBody();
    }

    public function getHeaders()
    {
        return $this->_value->getHeaders();
    }

    public function getHeader($header)
    {
        return $this->_value->getHeader($header);
    }

    public function getHeaderAsArray($header)
    {
        return $this->_value->getHeaderAsArray($header);
    }

    public function hasHeader($header)
    {
        return $this->_value->hasHeader($header);
    }

    public function removeHeader($header)
    {
        $this->_value->removeHeader($header);
    }

    public function addHeader($header, $value)
    {
        $this->_value->addHeader($header, $value);
    }

    public function addHeaders(array $headers)
    {
        $this->_value->addHeaders($headers);
    }

    public function setHeader($header, $value)
    {
        $this->_value->setHeader($header, $value);
    }

    public function setHeaders(array $headers)
    {
        $this->_value->setHeaders($headers);
    }
}
