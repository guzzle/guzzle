<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Transaction;

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
     * @param Transaction $transaction   Transaction that contains the request
     * @param RequestException     $e    Exception encountered
     * @param array                $transferStats Array of transfer statistics
     */
    public function __construct(
        Transaction $transaction,
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
        $trans = $this->getTransaction();
        $trans->response = $response;
        RequestEvents::emitComplete($trans);
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
}
