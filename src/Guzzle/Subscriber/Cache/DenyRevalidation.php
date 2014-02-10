<?php

namespace Guzzle\Subscriber\Cache;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Never performs cache revalidation and just assumes the request is invalid
 */
class DenyRevalidation extends DefaultRevalidation
{
    public function __construct() {}

    public function revalidate(RequestInterface $request, ResponseInterface $response)
    {
        return false;
    }
}
