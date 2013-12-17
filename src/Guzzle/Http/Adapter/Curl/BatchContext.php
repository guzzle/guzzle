<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\TransactionInterface;

/**
 * Provides context for a Curl transaction, including active handles,
 * pending transactions, and whether or not this is a batch or single
 * transaction.
 */
class BatchContext
{
    /** @var resource Curl multi resource */
    private $multi;

    /** @var \SplObjectStorage Map of transactions to curl resources */
    private $handles;

    /** @var \Iterator Yields pending transactions */
    private $pending;

    /** @var bool Whether or not to throw transactions */
    private $throwsExceptions;

    /**
     * @param resource  $multiHandle      Initialized curl_multi resource
     * @param bool      $throwsExceptions Whether or not exceptions are thrown
     * @param \Iterator $pending          Iterator yielding pending transactions
     */
    public function __construct(
        $multiHandle,
        $throwsExceptions,
        \Iterator $pending = null
    ) {
        $this->multi = $multiHandle;
        $this->handles = new \SplObjectStorage();
        $this->throwsExceptions = $throwsExceptions;
        $this->pending = $pending;
    }

    /**
     * Find a transaction for a given curl handle
     *
     * @param resource $handle Curl handle
     *
     * @return TransactionInterface
     * @throws \RuntimeException if a transaction is not found
     */
    public function findTransaction($handle)
    {
        foreach ($this->handles as $transaction) {
            if ($this->handles[$transaction] === $handle) {
                return $transaction;
            }
        }

        throw new \RuntimeException('No curl handle was found');
    }

    /**
     * Returns true if there are any remaining pending transactions
     *
     * @return bool
     */
    public function hasPending()
    {
        return $this->pending ? $this->pending->valid() : false;
    }

    /**
     * Pop the next transaction from the transaction queue
     *
     * @return null|TransactionInterface
     */
    public function nextPending()
    {
        if ($this->pending && $this->pending->valid()) {
            $current = $this->pending->current();
            $this->pending->next();
            return $current;
        }

        return null;
    }

    /**
     * Checks if the batch is to throw exceptions on error
     *
     * @return bool
     */
    public function throwsExceptions()
    {
        return $this->throwsExceptions;
    }

    /**
     * Get the curl_multi handle
     *
     * @return resource
     */
    public function getMultiHandle()
    {
        return $this->multi;
    }

    /**
     * Add a transaction to the multi handle
     *
     * @param TransactionInterface $transaction Transaction to add
     * @param resource             $handle      Resource to associated with the handle
     *
     * @throws \RuntimeException If the handle is already registered
     */
    public function addTransaction(TransactionInterface $transaction, $handle)
    {
        if (isset($this->handles[$transaction])) {
            throw new \RuntimeException('Transaction already registered');
        }

        CurlAdapter::checkCurlMultiResult(curl_multi_add_handle($this->multi, $handle));
        $this->handles[$transaction] = $handle;
    }

    /**
     * Remove a transaction and associated handle from the context
     *
     * @param TransactionInterface $transaction Transaction to remove
     *
     * @return resource Returns the curl handle
     * @throws \RuntimeException if the transaction is not found
     */
    public function removeTransaction(TransactionInterface $transaction)
    {
        if (!isset($this->handles[$transaction])) {
            throw new \RuntimeException('Transaction not registered');
        }

        $handle = $this->handles[$transaction];
        CurlAdapter::checkCurlMultiResult(curl_multi_remove_handle($this->multi, $handle));
        curl_close($handle);
        unset($this->handles[$transaction]);

        return $handle;
    }
}
