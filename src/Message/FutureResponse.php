<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Exception\CancelledFutureAccessException;
use GuzzleHttp\Ring\FutureInterface;
use GuzzleHttp\Transaction;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Represents a response that has not been fulfilled.
 *
 * When created, you must provide a function that is used to dereference the
 * future result and return it's value. You can optionally provide a function
 * that can be used to cancel the future from completing if possible.
 *
 * @property Transaction transaction
 */
class FutureResponse implements ResponseInterface, FutureInterface
{
    /** @var callable|null */
    private $dereffn;

    /** @var callable|null */
    private $cancelfn;

    /** @var bool */
    private $isCancelled = false;

    /**
     * @param callable $deref  Function that blocks until the future is
     *                         complete. This function MUST return a
     *                         Transaction object.
     * @param callable $cancel Function that is called that cancels the future
     *                         from completing if possible. The function MUST
     *                         return true on success or false on failure.
     */
    public function __construct(callable $deref, callable $cancel = null)
    {
        $this->dereffn = $deref;
        $this->cancelfn = $cancel;
    }

    public function deref()
    {
        return $this->transaction->response;
    }

    public function realized()
    {
        return $this->dereffn === null && !$this->isCancelled;
    }

    public function cancel()
    {
        // Cannot cancel a cancelled or completed future.
        if (!$this->dereffn && !$this->cancelfn) {
            return false;
        }

        $this->dereffn = null;
        $this->isCancelled = true;

        // if no cancel function is provided, then we cannot truly cancel.
        if (!$this->cancelfn) {
            return false;
        }

        // Return the result of invoking the cancel function.
        $cancelfn = $this->cancelfn;
        $this->cancelfn = null;

        return $cancelfn($this);
    }

    public function cancelled()
    {
        return $this->isCancelled;
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
        $this->transaction = $dereffn();
        $this->dereffn = $this->cancelfn = null;

        if (!$this->transaction instanceof Transaction) {
            throw new \RuntimeException('Future did not return a valid '
                . 'transaction. Got ' . gettype($this->transaction));
        }

        return $this->transaction;
    }
}
