<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

/**
 * Never performs cache revalidation and just assumes the request is still ok
 */
class SkipRevalidation implements RevalidationInterface
{
    public function revalidate(RequestInterface $request, Response $response)
    {
        return true;
    }
}
