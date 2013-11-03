<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Event\AbstractTransferStatsEvent;

/**
 * Strategy used to retry HTTP requests when the response's reason phrase matches one of the registered phrases.
 */
class ReasonPhraseFilter extends AbstractRetryFilter
{
    protected function should($retries, AbstractTransferStatsEvent $event)
    {
        if (!($response = $event->getResponse())) {
            return false;
        }

        return isset($this->errorCodes[$response->getReasonPhrase()]);
    }
}
