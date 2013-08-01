<?php

namespace Guzzle\Http\Exception;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\ClientInterface;

/**
 * Exception encountered while transferring multiple requests in parallel
 */
class BatchException extends TransferException
{
    /** @var ClientInterface */
    protected $client;

    /** @var Transaction */
    protected $transaction;

    public function __construct(
        Transaction $transaction,
        ClientInterface $client,
        \Exception $previous = null
    ) {
        $this->client = $client;
        $this->transaction = $transaction;
        $message = "Batch transaction error: \n";
        foreach ($transaction->getExceptions() as $e) {
            $message .= ' - ' . $e->getMessage() . "\n";
        }
        parent::__construct($message, 0, $previous);
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
     * Get the client that sent the transaction
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }
}
