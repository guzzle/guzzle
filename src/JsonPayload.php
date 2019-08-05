<?php

namespace GuzzleHttp;

class JsonPayload
{
    /**
     * @var array
     */
    private $data;

    /**
     * @var int
     */
    private $options;

    public function __construct(array $data, $options = 0)
    {
        $this->data = $data;
        $this->options = $options;
    }

    public function serialize()
    {
        return  \GuzzleHttp\json_encode($this->data, $this->options);
    }
}