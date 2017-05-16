<?php

namespace Guzzle\Plugin\Mock\RequestMatcher;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

/**
 * Matches a request to a response.
 */
interface RequestMatcherInterface
{
    /**
     * Try and match a request to a response.
     *
     * @param RequestInterface $request Request.
     *
     * @return Response|null Matched response, or `null`.
     */
    public function match(RequestInterface $request);
}
