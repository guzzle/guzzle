<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Event\AbstractTransferStatsEvent;

/**
 * Strategy used to retry HTTP requests based on the response code.
 *
 * Retries 500 and 503 error by default.
 */
class HttpStatusFilter extends AbstractRetryFilter
{
    /** @var array Default cURL errors to retry */
    protected static $defaultErrorCodes = [500, 503];

    protected  function should($retries, AbstractTransferStatsEvent $event)
    {
        if (!($response = $event->getResponse())) {
            return false;
        }

        return isset($this->errorCodes[$response->getStatusCode()]);
    }
}
