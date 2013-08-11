<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Header\HeaderCollection;
use Guzzle\Http\Header\HeaderFactory;
use Guzzle\Http\Header\HeaderFactoryInterface;
use Guzzle\Http\Header\HeaderInterface;

/**
 * Trait that implements HasHeadersInterface
 */
trait HasHeaders
{
    /** @var HeaderCollection HTTP header collection */
    protected $headers;

    /** @var HeaderFactoryInterface $headerFactory */
    private $headerFactory;

    /**
     * Set the header factory to use to create headers
     *
     * @param HeaderFactoryInterface $factory
     *
     * @return self
     */
    public function setHeaderFactory(HeaderFactoryInterface $factory)
    {
        $this->headerFactory = $factory;

        return $this;
    }

    /**
     * Get the header factory to use to create headers
     *
     * @return HeaderFactoryInterface
     */
    public function getHeaderFactory()
    {
        if (!$this->headerFactory) {
            $this->headerFactory = HeaderFactory::getInstance();
        }

        return $this->headerFactory;
    }

    public function addHeader($header, $value = null)
    {
        if (isset($this->headers[$header])) {
            return $this->headers[$header]->add($value);
        } elseif ($value instanceof HeaderInterface) {
            return $this->headers[$header] = $value;
        } else {
            return $this->headers[$header] = $this->getHeaderFactory()->createHeader($header, $value);
        }
    }

    public function addHeaders(array $headers)
    {
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    public function getHeader($header)
    {
        return $this->headers[$header];
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function setHeader($header, $value = null)
    {
        unset($this->headers[$header]);

        return $this->addHeader($header, $value);
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

    /**
     * This method must be called when using this trait!
     */
    protected function initHeaders()
    {
        $this->headers = new HeaderCollection();
    }
}
