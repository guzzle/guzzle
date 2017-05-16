<?php

namespace Guzzle\Plugin\Mock\UnmatchedRequestStrategy;

use Guzzle\Http\Message\RequestInterface;

/**
 * Strategy that does nothing if a request is not matched to a response,
 * allowing a real request to be made.
 */
class NullUnmatchedRequestStrategy implements UnmatchedRequestStrategyInterface
{
    public function handle(RequestInterface $request)
    {
        // do nothing
    }
}
