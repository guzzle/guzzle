<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Exception\HttpException;

/**
 * Strategy used to retry HTTP requests when the response's reason phrase matches one of the registered phrases.
 * If no other strategies are in the chain, it will retry immediately.
 */
class ReasonPhraseBackoffStrategy extends AbstractErrorCodeBackoffStrategy
{
    /**
     * {@inheritdoc}
     */
    protected function getDelay($retries, RequestInterface $request, Response $response = null, HttpException $e = null)
    {
        if ($response) {
            return isset($this->errorCodes[$response->getReasonPhrase()]);
        }
    }
}
