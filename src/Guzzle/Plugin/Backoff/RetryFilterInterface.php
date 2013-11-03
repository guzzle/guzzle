<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Event\AbstractTransferStatsEvent;

/**
 * Determines if a request should be retried
 */
interface RetryFilterInterface
{
    /**
     * Determines if a request should be retried
     *
     * @param int                        $retries Number of retries of the request
     * @param AbstractTransferStatsEvent $event   Event used to determine whether or not to retry
     *
     * @return bool|int Returns false to not retry or the number of seconds to delay between retries
     */
    public function shouldRetry($retries, AbstractTransferStatsEvent $event);
}
