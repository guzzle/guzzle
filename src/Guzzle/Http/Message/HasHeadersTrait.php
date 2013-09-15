<?php

namespace Guzzle\Http\Message;

/**
 * Trait that implements HasHeadersInterface
 */
trait HasHeadersTrait
{
    /** @var array HTTP header collection */
    private $headers = [];

    /** @var array mapping a lowercase header name to its name over the wire */
    private $headerNames = [];

    public function addHeader($header, $value)
    {
        $name = strtolower($header);

        if (!isset($this->headers[$name])) {
            $this->headerNames[$name] = $header;
            $this->headers[$name] = new HeaderValues($value);
        } elseif (is_string($value)) {
            $this->headers[$name][] = $value;
        } elseif (is_array($value) || $value instanceof HeaderValues) {
            foreach ($value as $v) {
                $this->headers[$name][] = $v;
            }
        }

        return $this;
    }

    public function getHeader($header)
    {
        $name = strtolower($header);

        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    public function getHeaders()
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[$this->headerNames[$name]] = $values;
        }

        return $headers;
    }

    public function setHeader($header, $value)
    {
        $name = strtolower($header);
        $this->headerNames[$name] = $header;

        if (!($value instanceof HeaderValuesInterface)) {
             $value = new HeaderValues($value);
        }

        $this->headers[$name] = $value;

        return $this;
    }

    public function setHeaders(array $headers)
    {
        $this->headers = $this->headerNames = [];
        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }

        return $this;
    }

    public function hasHeader($header)
    {
        return isset($this->headers[strtolower($header)]);
    }

    public function removeHeader($header)
    {
        $name = strtolower($header);
        unset($this->headers[$name]);
        unset($this->headerNames[$name]);

        return $this;
    }
}
