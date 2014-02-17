<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Cache revalidation interface
 */
interface RevalidationInterface
{
    /**
     * Performs a cache revalidation
     *
     * @param RequestInterface  $request    Request to revalidate
     * @param ResponseInterface $response   Response that was received
     *
     * @return bool Returns true if the request can be cached
     */
    public function revalidate(RequestInterface $request, ResponseInterface $response);

    /**
     * Returns true if the response should be revalidated
     *
     * @param RequestInterface  $request  Request to check
     * @param ResponseInterface $response Response to check
     *
     * @return bool
     */
    public function shouldRevalidate(RequestInterface $request, ResponseInterface $response);
}
