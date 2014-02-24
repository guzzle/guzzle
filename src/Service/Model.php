<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\GetPathTrait;

/**
 * Default model implementation.
 */
class Model implements ModelInterface
{
    use GetPathTrait;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function hasKey($name)
    {
        return isset($this->data[$name]);
    }
}
