<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Adapter\TransactionQueueInterface;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\MessageFactoryInterface;

/**
 * Provides an abstraction over curl_multi handles
 */
class CurlTransactionQueue implements TransactionQueueInterface
{
    /** @var resource */
    private $multi;

    /** @var CurlFactory */
    private $curlFactory;

    /** @var MessageFactory */
    private $messageFactory;

    /** @var \SplObjectStorage */
    private $handles;

    /** @var \Iterator */
    private $transactions;

    /**
     * @param \Iterator               $transactions   Collection of all transactions to send
     * @param resource                $multiHandle    Initialized curl_multi resource
     * @param CurlFactory             $curlFactory    Factory used to create cURL handles
     * @param MessageFactoryInterface $messageFactory Factory used to create messages
     * @throws \InvalidArgumentException If $transactions is not an array or \Iterator
     */
    public function __construct(
        \Iterator $transactions,
        $multiHandle,
        CurlFactory $curlFactory,
        MessageFactoryInterface $messageFactory
    ) {
        $this->transactions = $transactions;
        $this->multi = $multiHandle;
        $this->handles = new \SplObjectStorage();
        $this->curlFactory = $curlFactory;
        $this->messageFactory = $messageFactory;
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

    public function getActiveCount()
    {
        return count($this->handles);
    }

    public function getActiveTransactions()
    {
        return iterator_to_array($this->handles);
    }

    public function getTransactionResource(TransactionInterface $transaction)
    {
        return isset($this->handles[$transaction]) ? $this->handles[$transaction] : null;
    }

    public function addTransaction(TransactionInterface $transaction)
    {
        if (isset($this->handles[$transaction])) {
            throw new \RuntimeException('Transaction already registered');
        }

        $handle = $this->curlFactory->createHandle(
            $transaction,
            $this->messageFactory
        );

        CurlAdapter::checkCurlMultiResult(curl_multi_add_handle($this->multi, $handle));
        $this->handles[$transaction] = $handle;
    }

    public function removeTransaction(TransactionInterface $transaction)
    {
        if (isset($this->handles[$transaction])) {
            curl_multi_remove_handle($this->multi, $this->handles[$transaction]);
            curl_close($this->handles[$transaction]);
            unset($this->handles[$transaction]);
        }

        // Add the next transaction if there are more in the queue
        if ($this->transactions->valid()) {
            $this->transactions->next();
            $this->addTransaction($this->transactions->current());
        }
    }
}
