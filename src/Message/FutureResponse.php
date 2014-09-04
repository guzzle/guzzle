<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Transaction;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Represents a response that has not been fulfilled.
 *
 * @property Transaction transaction
 */
class FutureResponse implements ResponseInterface
{
    /** @var callable */
    private $deref;

    public function __construct(callable $deref)
    {
        $this->deref = $deref;
    }

    public function wait()
    {
        $this->__get('transaction');
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
        if ($name == 'transaction') {
            return $this->transaction = call_user_func($this->deref);
        }

        throw new \RuntimeException('Unknown property: ' . $name);
    }
}
