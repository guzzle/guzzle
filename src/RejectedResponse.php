<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\StreamableInterface;

/**
 * A thennable failed response.
 */
class RejectedResponse extends RejectedPromise implements ResponsePromiseInterface
{
    private $e;

    public function __construct(\Exception $e)
    {
        $this->e = $e;
        parent::__construct($e);
    }

    public function getStatusCode()
    {
        throw $this->e;
    }

    public function withStatus($code, $reasonPhrase = null)
    {
        throw $this->e;
    }

    public function getReasonPhrase()
    {
        throw $this->e;
    }

    public function getProtocolVersion()
    {
        throw $this->e;
    }

    public function withProtocolVersion($version)
    {
        throw $this->e;
    }

    public function getHeaders()
    {
        throw $this->e;
    }

    public function hasHeader($name)
    {
        throw $this->e;
    }

    public function getHeader($name)
    {
        throw $this->e;
    }

    public function getHeaderLines($name)
    {
        throw $this->e;
    }

    public function withHeader($name, $value)
    {
        throw $this->e;
    }

    public function withAddedHeader($name, $value)
    {
        throw $this->e;
    }

    public function withoutHeader($name)
    {
        throw $this->e;
    }

    public function getBody()
    {
        throw $this->e;
    }

    public function withBody(StreamableInterface $body)
    {
        throw $this->e;
    }
}
