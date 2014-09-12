<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Ring\BaseFutureTrait;
use GuzzleHttp\Ring\Exception\CancelledFutureAccessException;
use GuzzleHttp\Ring\FutureInterface;
use GuzzleHttp\Transaction;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Represents a response that has not been fulfilled.
 *
 * When created, you must provide a function that is used to dereference the
 * future result and return it's value. The function has no arguments and MUST
 * return an instance of a GuzzleHttp\Transaction object.
 *
 * You can optionally provide a function in the constructor that can be used to
 * cancel the future from completing if possible. This function has no
 * arguments and returns a boolean value representing whether or not the
 * response could be cancelled.
 *
 * @property Transaction transaction
 */
class FutureResponse implements ResponseInterface, FutureInterface
{
    use BaseFutureTrait;

    public function deref()
    {
        return $this->transaction->response;
    }

    public function getStatusCode()
    {
        return $this->transaction->response->getStatusCode();
    }

    public function getReasonPhrase()
    {
        return $this->transaction->response->getReasonPhrase();
    }

    public function getEffectiveUrl()
    {
        return $this->transaction->response->getEffectiveUrl();
    }

    public function setEffectiveUrl($url)
    {
        $this->transaction->response->setEffectiveUrl($url);
    }

    public function json(array $config = [])
    {
        return $this->transaction->response->json($config);
    }

    public function xml(array $config = [])
    {
        return $this->transaction->response->xml($config);
    }

    public function __toString()
    {
        return $this->transaction->response->__toString();
    }

    public function getProtocolVersion()
    {
        return $this->transaction->response->getProtocolVersion();
    }

    public function setBody(StreamInterface $body = null)
    {
        $this->transaction->response->setBody($body);
    }

    public function getBody()
    {
        return $this->transaction->response->getBody();
    }

    public function getHeaders()
    {
        return $this->transaction->response->getHeaders();
    }

    public function getHeader($header)
    {
        return $this->transaction->response->getHeader($header);
    }

    public function getHeaderLines($header)
    {
        return $this->transaction->response->getHeaderLines($header);
    }

    public function hasHeader($header)
    {
        return $this->transaction->response->hasHeader($header);
    }

    public function removeHeader($header)
    {
        $this->transaction->response->removeHeader($header);
    }

    public function addHeader($header, $value)
    {
        $this->transaction->response->addHeader($header, $value);
    }

    public function addHeaders(array $headers)
    {
        $this->transaction->response->addHeaders($headers);
    }

    public function setHeader($header, $value)
    {
        $this->transaction->response->setHeader($header, $value);
    }

    public function setHeaders(array $headers)
    {
        $this->transaction->response->setHeaders($headers);
    }

    /** @internal */
    public function __get($name)
    {
        if ($name !== 'transaction') {
            throw new \RuntimeException('Unknown property: ' . $name);
        } elseif ($this->isCancelled) {
            throw new CancelledFutureAccessException('You are attempting '
                . 'to access a future that has been cancelled.');
        }

        $dereffn = $this->dereffn;
        $this->dereffn = $this->cancelfn = null;
        $this->transaction = $dereffn();

        if (!$this->transaction instanceof Transaction) {
            throw new \RuntimeException('Future did not return a valid '
                . 'transaction. Got ' . gettype($this->transaction));
        }

        return $this->transaction;
    }
}
