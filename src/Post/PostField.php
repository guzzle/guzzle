<?php

namespace GuzzleHttp\Post;

class PostField implements PostFieldInterface
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $value;

    /**
     * @var array
     */
    private $headers;

    /**
     * @param string $value
     * @param string $name
     * @param array  $headers
     */
    public function __construct($value, $name = null, $headers = [])
    {
        $this->name = $name;
        $this->value = $value;
        $this->headers = $headers;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function addHeader($name, $value)
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function __toString()
    {
        return $this->getValue();
    }
}
