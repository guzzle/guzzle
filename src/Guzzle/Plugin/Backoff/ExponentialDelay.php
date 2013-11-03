<?php

namespace Guzzle\Plugin\Backoff;

/**
 * Implements an exponential backoff retry strategy.
 */
class ExponentialDelay
{
    public function __invoke($retries)
    {
        return (int) pow(2, $retries - 1);
    }
}
