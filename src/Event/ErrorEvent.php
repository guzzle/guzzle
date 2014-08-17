<?php

namespace GuzzleHttp\Event;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Adapter\TransactionInterface;

/**
 * Event object emitted after a request has been sent and an error was
 * encountered.
 *
 * You may intercept the exception and inject a response into the event to
 * rescue the request.
 */
class ErrorEvent extends AbstractTransferEvent
{
    private $exception;

    /**
     * @param TransactionInterface $transaction   Transaction that contains the request
     * @param RequestException     $e             Exception encountered
     * @param array                $transferStats Array of transfer statistics
     */
    public function __construct(
        TransactionInterface $transaction,
        RequestException $e,
        $transferStats = []
    ) {
        parent::__construct($transaction, $transferStats);
        $this->exception = $e;
    }

    /**
     * Intercept the exception and inject a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->stopPropagation();
        $this->getTransaction()->setResponse($response);
        $this->exception->setThrowImmediately(false);
        RequestEvents::emitComplete($this->getTransaction());
    }

    /**
     * Get the exception that was encountered
     *
     * @return RequestException
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * Get the response the was received (if any)
     *
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->getException()->getResponse();
    }

    /**
     * Request that a ParallelAdapterInterface throw the associated exception
     * if the exception is unhandled.
     *
     * If the error event was not emitted from a ParallelAdapterInterface, then
     * the effect of this method is nil.
     *
     * @param bool $throwImmediately Whether or not to throw immediately
     */
    public function throwImmediately($throwImmediately)
    {
        $this->exception->setThrowImmediately($throwImmediately);
    }
}
