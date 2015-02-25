<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\FulfilledPromise;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamableInterface;

/**
 * A thennable successfully completed response.
 */
class FulfilledResponse extends FulfilledPromise implements ResponsePromiseInterface
{
    private $response;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
        parent::__construct($response);
    }

    public function getStatusCode()
    {
        return $this->response->getStatusCode();
    }

    public function withStatus($code, $reasonPhrase = null)
    {
        return $this->response->withStatus($code, $reasonPhrase);
    }

    public function getReasonPhrase()
    {
        return $this->response->getReasonPhrase();
    }

    public function getProtocolVersion()
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion($version)
    {
        return $this->response->withProtocolVersion($version);
    }

    public function getHeaders()
    {
        return $this->response->getHeaders();
    }

    public function hasHeader($name)
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader($name)
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLines($name)
    {
        return $this->response->getHeaderLines($name);
    }

    public function withHeader($name, $value)
    {
        return $this->response->withHeader($name, $value);
    }

    public function withAddedHeader($name, $value)
    {
        return $this->response->withAddedHeader($name, $value);
    }

    public function withoutHeader($name)
    {
        return $this->response->withoutHeader($name);
    }

    public function getBody()
    {
        return $this->response->getBody();
    }

    public function withBody(StreamableInterface $body)
    {
        return $this->response->withBody($body);
    }
}
