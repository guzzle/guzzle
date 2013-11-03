<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\TransactionInterface;

/**
 * Provides an abstraction over curl_multi handles
 */
class BatchContext
{
    /** @var resource */
    private $multi;

    /** @var array Array of {@see TransactionInterface} */
    private $transactions = [];

    /** @var \SplObjectStorage */
    private $handles;

    /**
     * @param resource $mutliHandle Initialized curl_multi resource
     */
    public function __construct($mutliHandle)
    {
        $this->multi = $mutliHandle;
        $this->handles = new \SplObjectStorage();
    }

    /**
     * Get all of the transactions in the context
     *
     * @return array
     */
    public function getTransactions()
    {
        return iterator_to_array($this->handles);
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
     * Get a curl easy handle for a specific transaction
     *
     * @param TransactionInterface $transaction Transaction associated with the handle
     *
     * @return resource|null Returns the handle if found or null if not found
     */
    public function getHandle(TransactionInterface $transaction)
    {
        return isset($this->handles[$transaction]) ? $this->handles[$transaction] : null;
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
     */
    public function removeTransaction(TransactionInterface $transaction)
    {
        if (isset($this->handles[$transaction])) {
            CurlAdapter::checkCurlMultiResult(curl_multi_remove_handle($this->multi, $this->handles[$transaction]));
            curl_close($this->handles[$transaction]);
            unset($this->handles[$transaction]);
        }
    }
}
