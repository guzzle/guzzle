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
     */
    public function retry()
    {
        $this->transaction->response = null;
        $this->transaction->exception = null;
        $this->transaction->state = 'before';
        $this->stopPropagation();
    }
}
