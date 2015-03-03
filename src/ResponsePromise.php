<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\StreamableInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @property ResponseInterface $_response
 */
class ResponsePromise extends Promise implements ResponsePromiseInterface
{
    /**
     * Create a response promise from a regular promise.
     *
     * @param PromiseInterface $promise Promise value.
     *
     * @return FulfilledResponse|ResponsePromise
     * @throws \UnexpectedValueException
     */
    public static function fromPromise(PromiseInterface $promise)
    {
        $state = $promise->getState();
        if ($state === 'pending') {
            $next = new ResponsePromise([$promise, 'wait'], [$promise, 'cancel']);
            $promise->then([$next, 'resolve'], [$next, 'reject']);
            return $next;
        } elseif ($state === 'fulfilled') {
            return new FulfilledResponse($promise->wait());
        } elseif ($state === 'rejected' || $state === 'cancelled') {
            try {
                $promise->wait();
            } catch (\Exception $e) {
                return new RejectedResponse($e);
            }
        }

        throw new \UnexpectedValueException("Invalid promise state: {$state}");
    }

    public function __get($name)
    {
        if ($name == '_response') {
            return $this->_response = $this->wait();
        }

        throw new \BadMethodCallException("Unknown property {$name}");
    }

    public function getStatusCode()
    {
        return $this->_response->getStatusCode();
    }

    public function withStatus($code, $reasonPhrase = null)
    {
        return $this->_response->withStatus($code, $reasonPhrase);
    }

    public function getReasonPhrase()
    {
        return $this->_response->getReasonPhrase();
    }

    public function getProtocolVersion()
    {
        return $this->_response->getProtocolVersion();
    }

    public function withProtocolVersion($version)
    {
        return $this->_response->withProtocolVersion($version);
    }

    public function getHeaders()
    {
        return $this->_response->getHeaders();
    }

    public function hasHeader($name)
    {
        return $this->_response->hasHeader($name);
    }

    public function getHeader($name)
    {
        return $this->_response->getHeader($name);
    }

    public function getHeaderLines($name)
    {
        return $this->_response->getHeaderLines($name);
    }

    public function withHeader($name, $value)
    {
        return $this->_response->withHeader($name, $value);
    }

    public function withAddedHeader($name, $value)
    {
        return $this->_response->withAddedHeader($name, $value);
    }

    public function withoutHeader($name)
    {
        return $this->_response->withoutHeader($name);
    }

    public function getBody()
    {
        return $this->_response->getBody();
    }

    public function withBody(StreamableInterface $body)
    {
        return $this->_response->withBody($body);
    }

    public function resolve($value)
    {
        if ($value instanceof ResponseInterface
            || $value instanceof RejectedPromise
        ) {
            parent::resolve($value);
            return;
        }

        throw new \InvalidArgumentException('A response promise must be '
            . 'resolved with a Psr\Http\Message\ResponseInterface or a '
            . 'GuzzleHttp\RejectedPromise. Found ' . Utils::describeType($value));
    }
}
