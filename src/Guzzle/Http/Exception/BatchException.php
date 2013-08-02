<?php

namespace Guzzle\Http\Exception;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\ClientInterface;

/**
 * Exception encountered while transferring multiple requests in parallel
 */
class BatchException extends TransferException
{
    /** @var Transaction */
    protected $transaction;

    public function __construct(
        Transaction $transaction,
        \Exception $previous = null
    ) {
        $this->transaction = $transaction;
        $message = "One or more exceptions were encountered during a batch transaction: \n";
        foreach ($transaction->getExceptions() as $e) {
            $message .= ' - [' . get_class($e) . '] ' . $e->getMessage() . "\n";
        }
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the client that sent the transaction
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->transaction->getClient();
    }

    /**
     * Get the transaction that encountered errors
     *
     * @return Transaction
     */
    public function getTransaction()
    {
        return $this->transaction;
    }

    /**
     * Get a hash of request objects to responses
     *
     * @return \SplObjectStorage
     */
    public function getResponses()
    {
        return $this->getTransaction()->getResponses();
    }

    /**
     * Get a hash of request objects to exceptions
     *
     * @return \SplObjectStorage
     */
    public function getExceptions()
    {
        return $this->getTransaction()->getExceptions();
    }
}
