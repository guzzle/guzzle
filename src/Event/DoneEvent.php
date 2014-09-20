<?php
namespace GuzzleHttp\Event;

/**
 * A terminal event emitted when a request has completed.
 *
 * This event is emitted for both successful responses and responses that
 * encountered an exception. You need to check if an exception is present
 * in your listener to know the difference.
 */
class DoneEvent extends AbstractTransferEvent
{
    /**
     * Get the exception that was encountered (if any)
     *
     * @return \Exception|null
     */
    public function getException()
    {
        return $this->transaction->exception;
    }
}
