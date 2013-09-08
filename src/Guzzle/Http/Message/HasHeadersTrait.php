<?php

namespace Guzzle\Http\Message;

/**
 * Trait that implements HasHeadersInterface
 */
trait HasHeadersTrait
{
    /** @var HeaderCollection HTTP header collection */
    private $headers;

    public function addHeader($header, $value)
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->headers->addHeader($header, $v);
            }
        } else {
            $this->headers->addHeader($header, $value);
        }

        return $this;
    }

    public function getHeader($header)
    {
        return $this->headers->getHeaderString($header);
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function setHeader($header, $value)
    {
        $this->headers[$header] = $value;

        return $this;
    }

    public function setHeaders(array $headers)
    {
        $this->headers->clear();
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    public function hasHeader($header)
    {
        return isset($this->headers[$header]);
    }

    public function removeHeader($header)
    {
        unset($this->headers[$header]);

        return $this;
    }
}
