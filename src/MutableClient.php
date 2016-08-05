<?php

namespace GuzzleHttp;

/**
 * Class MutableClient
 * @package GuzzleHttp
 * This is an extension of the Client class which allows the configuration to be set anytime.
 */
class MutableClient extends Client
{
    /**
     * @param $key
     * @param $value
     */
    public function setConfigOption($key, $value)
    {
        $this->config[$key] = $value;
    }
}
