<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Wraps a request with an {@see UnvalidatedUri} without invoking validation.
 */
final class UnvalidatedUriRequest implements RequestInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var UriInterface */
    private $uri;

    public function __construct(RequestInterface $request, UriInterface $uri)
    {
        $this->request = $request;
        $this->uri = $uri;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface
    {
        return new self($this->request, $uri);
    }

    public function getRequestTarget(): string
    {
        return $this->request->getRequestTarget();
    }

    public function withRequestTarget($requestTarget): RequestInterface
    {
        return new self($this->request->withRequestTarget($requestTarget), $this->uri);
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function withMethod($method): RequestInterface
    {
        return new self($this->request->withMethod($method), $this->uri);
    }

    public function getProtocolVersion(): string
    {
        return $this->request->getProtocolVersion();
    }

    public function withProtocolVersion($version): RequestInterface
    {
        return new self($this->request->withProtocolVersion($version), $this->uri);
    }

    public function getHeaders(): array
    {
        return $this->request->getHeaders();
    }

    public function hasHeader($name): bool
    {
        return $this->request->hasHeader($name);
    }

    public function getHeader($name): array
    {
        return $this->request->getHeader($name);
    }

    public function getHeaderLine($name): string
    {
        return $this->request->getHeaderLine($name);
    }

    public function withHeader($name, $value): RequestInterface
    {
        return new self($this->request->withHeader($name, $value), $this->uri);
    }

    public function withAddedHeader($name, $value): RequestInterface
    {
        return new self($this->request->withAddedHeader($name, $value), $this->uri);
    }

    public function withoutHeader($name): RequestInterface
    {
        return new self($this->request->withoutHeader($name), $this->uri);
    }

    public function getBody(): StreamInterface
    {
        return $this->request->getBody();
    }

    public function withBody(StreamInterface $body): RequestInterface
    {
        return new self($this->request->withBody($body), $this->uri);
    }
}
