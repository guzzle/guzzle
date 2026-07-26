<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use Psr\Http\Message\UriInterface;

/**
 * Stores hosts without validation for third-party UriInterface tests.
 */
final class UnvalidatedUri implements UriInterface
{
    /** @var string */
    private $scheme;

    /** @var string */
    private $host;

    /** @var int|null */
    private $port;

    /** @var string */
    private $path;

    public function __construct(string $scheme, string $host, ?int $port = null, string $path = '/')
    {
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        return $this->port === null ? $this->host : $this->host.':'.$this->port;
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme($scheme): UriInterface
    {
        return new self((string) $scheme, $this->host, $this->port, $this->path);
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        return $this;
    }

    public function withHost($host): UriInterface
    {
        return new self($this->scheme, (string) $host, $this->port, $this->path);
    }

    public function withPort($port): UriInterface
    {
        return new self($this->scheme, $this->host, $port === null ? null : (int) $port, $this->path);
    }

    public function withPath($path): UriInterface
    {
        return new self($this->scheme, $this->host, $this->port, (string) $path);
    }

    public function withQuery($query): UriInterface
    {
        return $this;
    }

    public function withFragment($fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->scheme.'://'.$this->getAuthority().$this->path;
    }
}
