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
    /**
     * @param Transaction      $transaction   Transaction that contains the request
     * @param RequestException $e             Exception encountered
     * @param array            $transferStats Array of transfer statistics
     */
    public function __construct(
        Transaction $transaction,
        RequestException $e,
        $transferStats = []
    ) {
        $transaction->exception = $e;

        // Set the response on the transaction if one is present on the except.
        if ($response = $e->getResponse()) {
            $transaction->response = $response;
        }

        parent::__construct($transaction, $transferStats);
    }

    /**
     * Intercept the exception and inject a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->stopPropagation();
        $this->transaction->response = $response;
        $this->transaction->exception = null;
        RequestEvents::emitComplete($this->transaction);
    }

    /**
     * Get the exception that was encountered
     *
     * @return RequestException
     */
    public function getException()
    {
        return $this->transaction->exception;
    }
}
