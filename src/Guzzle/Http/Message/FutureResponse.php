<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Stream\StreamInterface;

class FutureResponse implements FutureResponseInterface
{
    private $adapter;
    private $transaction;
    private $response;

    public function __construct(Transaction $transaction, AdapterInterface $adapter)
    {
        $this->transaction = $transaction;
        $this->adapter = $adapter;
    }

    public function send()
    {
        $this->getResponse();

        return $this;
    }

    public function getTransaction()
    {
        return $this->transaction;
    }

    public function getAdapter()
    {
        return $this->adapter;
    }

    public function __toString()
    {
        return (string) $this->getResponse();
    }

    public function getStatusCode()
    {
        return $this->getResponse()->getStatusCode();
    }

    public function getReasonPhrase()
    {
        return $this->getResponse()->getReasonPhrase();
    }

    public function getEffectiveUrl()
    {
        // Clients attempt to set an effective URL, so don't trigger a call
        return !$this->response
            ? null
            : $this->getResponse()->getEffectiveUrl();
    }

    public function setEffectiveUrl($url)
    {
        $this->getResponse()->setEffectiveUrl($url);

        return $this;
    }

    public function json()
    {
        return $this->getResponse()->json();
    }

    public function xml()
    {
        return $this->getResponse()->xml();
    }

    public function getProtocolVersion()
    {
        return $this->getResponse()->getProtocolVersion();
    }

    public function setBody(StreamInterface $body = null)
    {
        $this->getResponse()->setBody($body);

        return $this;
    }

    public function getBody()
    {
        return $this->getResponse()->getBody();
    }

    public function getHeaders()
    {
        return $this->getResponse()->getHeaders();
    }

    public function getHeader($header)
    {
        return $this->getResponse()->getHeader($header);
    }

    public function hasHeader($header)
    {
        return $this->getResponse()->hasHeader($header);
    }

    public function removeHeader($header)
    {
        return $this->getResponse()->removeHeader($header);
    }

    public function addHeader($header, $value = null)
    {
        return $this->getResponse()->addHeader($header, $value);
    }

    public function setHeader($header, $value = null)
    {
        return $this->getResponse()->setHeader($header, $value);
    }

    public function setHeaders(array $headers)
    {
        return $this->getResponse()->setHeaders($headers);
    }

    private function getResponse()
    {
        if (!$this->response) {
            $this->getAdapter()->send($this->getTransaction());
            $this->response = $this->transaction->getResponse();
        }

        return $this->response;
    }
}
