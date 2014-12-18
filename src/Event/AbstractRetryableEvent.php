<?php
namespace GuzzleHttp\Event;

/**
 * Abstract request event that can be retried.
 */
class AbstractRetryableEvent extends AbstractTransferEvent
{
    /**
     * Mark the request as needing a retry and stop event propagation.
     *
     * This action allows you to retry a request without emitting the "end"
     * event multiple times for a given request. When retried, the request
     * emits a before event and is then sent again using the client that sent
     * the original request.
     *
     * When retrying, it is important to limit the number of retries you allow
     * to prevent infinite loops.
     *
     * This action can only be taken during the "complete" and "error" events.
     *
     * @param int $afterDelay If specified, the amount of time in milliseconds
     *                        to delay before retrying. Note that this must
     *                        be supported by the underlying RingPHP handler
     *                        to work properly. Set to 0 or provide no value
     *                        to retry immediately.
     */
    public function retry($afterDelay = 0)
    {
        // Setting the transition state to 'retry' will cause the next state
        // transition of the transaction to retry the request.
        $this->transaction->state = 'retry';

        if ($afterDelay) {
            $this->transaction->request->getConfig()->set('delay', $afterDelay);
        }

        $this->stopPropagation();
    }
}
