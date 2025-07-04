<?php

namespace GuzzleHttp\Handler;

interface CurlHandlePoolInterface
{
    /**
     * @return resource|\CurlHandle|false
     */
    public function get();

    /**
     * @param resource|\CurlHandle $handle
     */
    public function put($handle): void;
}
