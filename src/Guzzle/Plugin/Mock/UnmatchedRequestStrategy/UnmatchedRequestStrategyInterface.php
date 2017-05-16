<?php

namespace Guzzle\Plugin\Mock\UnmatchedRequestStrategy;

use Guzzle\Http\Message\RequestInterface;

/**
 * Defines what happens when a request is not matched to a response.
 */
interface UnmatchedRequestStrategyInterface
{
    /**
     * Handle an unmatched request.
     *
     * This may, for example set a response on the request, or do nothing.
     *
     * @param RequestInterface $request
     */
    public function handle(RequestInterface $request);
}
