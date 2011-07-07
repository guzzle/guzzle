<?php

namespace Guzzle\Http\Curl;

use Guzzle\Http\Message\RequestInterface;

/**
 * Curl factories generate cURL handles based on a RequestInterface object
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface CurlFactoryInterface
{
    /**
     * Get a cURL handle for an HTTP request
     *
     * @param RequestInterface $request Request object checking out the handle
     *
     * @return resource
     */
    function getHandle(RequestInterface $request);

    /**
     * Release a cURL handle back to the factory
     *
     * @param CurlHandle $handle Handle to release
     * @param bool $close (optional) Set to TRUE to close the handle
     *
     * @return CurlFactoryInterface
     */
    function releaseHandle(CurlHandle $handle);
}