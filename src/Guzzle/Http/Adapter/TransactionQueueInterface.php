<?php

namespace Guzzle\Http\Adapter;

/**
 * Manages a list of queued Transactions, active transactions, and data
 * associated with any transactions.
 */
interface TransactionQueueInterface
{
    /**
     * Get all of the active transactions
     *
     * @return array
     */
    public function getActiveTransactions();

    /**
     * Get a count of all of the active transactions
     *
     * @return array
     */
    public function getActiveCount();

    /**
     * Add a transaction to the active list
     *
     * @param TransactionInterface $transaction Transaction to add
     *
     * @throws \RuntimeException If the transaction is already present
     */
    public function addTransaction(TransactionInterface $transaction);

    /**
     * Remove an active transaction and any associated resources
     *
     * @param TransactionInterface $transaction Transaction to remove
     */
    public function removeTransaction(TransactionInterface $transaction);

    /**
     * Get an associated resource for the given Transaction
     *
     * @param TransactionInterface $transaction
     *
     * @return mixed Returns the relevant resource for the concrete batch context
     */
    public function getTransactionResource(TransactionInterface $transaction);
}
