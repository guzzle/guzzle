<?php

namespace GuzzleHttp\Message;

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
        static $valid = ['string' => true, 'integer' => true,
            'double' => true, 'array' => true];

        $type = gettype($value);
        if (!isset($valid[$type])) {
            throw new \InvalidArgumentException('Invalid header value');
        }

        if ($type == 'array') {
            $current = array_merge($this->getHeader($header, true), $value);
        } else {
            $current = $this->getHeader($header, true);
            $current[] = $value;
        }

        return $this->setHeader($header, $current);
    }

    public function addHeaders(array $headers)
    {
        foreach ($headers as $name => $header) {
            $this->addHeader($name, $header);
        }
    }

    public function getHeader($header, $asArray = false)
    {
        $name = strtolower($header);

        if (!isset($this->headers[$name])) {
            return $asArray ? [] : '';
        }

        return $asArray
            ? $this->headers[$name]
            : implode(', ', $this->headers[$name]);
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
        $header = trim($header);
        $name = strtolower($header);
        $this->headerNames[$name] = $header;

        switch (gettype($value)) {
            case 'string':
                $this->headers[$name] = [trim($value)];
                break;
            case 'integer':
            case 'double':
                $this->headers[$name] = [(string) $value];
                break;
            case 'array':
                $this->headers[$name] = array_map('trim', $value);
                break;
            default:
                throw new \InvalidArgumentException('Invalid header value '
                    . 'provided: ' . var_export($value, true));
        }

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
        unset($this->headers[$name], $this->headerNames[$name]);

        return $this;
    }
}
