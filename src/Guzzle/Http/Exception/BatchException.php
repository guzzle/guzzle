<?php

namespace Guzzle\Http\Exception;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Exception thrown when a batch transaction fails. Contains an iterator
 * that yields any remaining transactions that have not been sent.
 */
class BatchException extends RequestException
{
    public function __construct(
        $message = '',
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $previous = null,
        \Iterator $remaining
    ) {
        parent::__construct($message, $request, $response, $previous);
        $this->remaining = $remaining;
    }

    /**
     * Returns the remaining transactions from the batch transaction
     * that have not yet been completed.
     *
     * @return \Iterator Returns an iterator that yields TransactionInterface objects
     */
    public function getRemaining()
    {
        return $this->remaining;
    }
}
