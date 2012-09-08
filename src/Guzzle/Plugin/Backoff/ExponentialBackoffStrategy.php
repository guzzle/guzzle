<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Exception\HttpException;

/**
 * Implements an exponential backoff retry strategy. If no strategies are before this in the chain, then all requests
 * will be retried using exponential backoff.
 */
class ExponentialBackoffStrategy extends AbstractBackoffStrategy
{
    /**
     * {@inheritdoc}
     */
    protected function getDelay($retries, RequestInterface $request, Response $response = null, HttpException $e = null)
    {
        return (int) pow(2, $retries);
    }
}
