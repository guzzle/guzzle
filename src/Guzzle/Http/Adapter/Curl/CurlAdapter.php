<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\BatchAdapterInterface;
use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Exception\AdapterException;
use Guzzle\Http\Exception\BatchException;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\MessageFactoryInterface;

/**
 * HTTP adapter that uses cURL as a transport layer
 */
class CurlAdapter implements AdapterInterface, BatchAdapterInterface
{
    const ERROR_STR = 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html for an explanation of cURL errors';

    /** @var CurlFactory */
    private $curlFactory;
    /** @var MessageFactoryInterface */
    private $messageFactory;
    /** @var \Iterator */
    private $transactions;
    /** @var resource */
    private $multi;
    /** @var \SplObjectStorage */
    private $handles;

    /**
     * @param MessageFactoryInterface $messageFactory
     * @param array                   $options Array of options to use with the adapter
     *                                         - handle_factory: Optional factory used to create cURL handles
     */
    public function __construct(MessageFactoryInterface $messageFactory, array $options = [])
    {
        $this->multi = curl_multi_init();
        $this->handles = new \SplObjectStorage();
        $this->messageFactory = $messageFactory;
        $this->curlFactory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory();
    }

    public function __destruct()
    {
        foreach ($this->handles as $transaction) {
            $this->removeTransaction($transaction);
        }

        curl_multi_close($this->multi);
    }

    public function send(TransactionInterface $transaction)
    {
        $this->batch(new \ArrayIterator(array($transaction)), 1);

        return $transaction->getResponse();
    }

    public function batch(\Iterator $transactions, $parallel)
    {
        // Add the new transactions to any existing transactions
        if (!$this->transactions) {
            $this->transactions = $transactions;
        } elseif (!($this->transactions instanceof \AppendIterator)) {
            $append = new \AppendIterator();
            $append->append($this->transactions);
        } else {
            $this->transactions->append($transactions);
        }

        $total = 0;
        while ($this->transactions->valid() && $total < $parallel) {
            $current = $this->transactions->current();
            $this->addHandle($current);
            $total++;
            $this->transactions->next();
        }

        $this->perform();
    }

    /**
     * Execute and select curl handles
     */
    private function perform()
    {
        // The first curl_multi_select often times out no matter what, but is usually required for fast transfers
        $selectTimeout = 0.001;
        $active = false;

        do {
            while (($mrc = curl_multi_exec($this->multi, $active)) == CURLM_CALL_MULTI_PERFORM);
            $this->checkCurlMultiResult($mrc);
            $this->processMessages();
            if ($active && curl_multi_select($this->multi, $selectTimeout) === -1) {
                // Perform a usleep if a select returns -1: https://bugs.php.net/bug.php?id=61141
                usleep(150);
            }
            $selectTimeout = 1;
        } while ($active || $this->transactions->valid());
    }

    /**
     * Process any received curl multi messages
     */
    private function processMessages()
    {
        while ($done = curl_multi_info_read($this->multi)) {
            foreach ($this->handles as $transaction) {
                if ($this->handles[$transaction] === $done['handle']) {
                    $this->processResponse($transaction, $done);
                    // Add the next transaction if there are more in the queue
                    if ($this->transactions->valid()) {
                        $this->addHandle($this->transactions->current());
                        $this->transactions->next();
                    }
                    continue 2;
                }
            }
        }
    }

    /**
     * Check for errors and fix headers of a request based on a curl response
     * @throws RequestException on error
     */
    private function processResponse(TransactionInterface $transaction, array $curl)
    {
        $handle = $this->handles[$transaction];
        $this->removeTransaction($transaction);

        try {
            if (!$this->isCurlException($transaction, $curl)) {
                RequestEvents::emitAfterSend($transaction, curl_getinfo($handle));
            }
        } catch (RequestException $e) {
            $this->throwBatchException($e);
        }
    }

    private function addHandle(TransactionInterface $transaction)
    {
        if (isset($this->handles[$transaction])) {
            throw new \RuntimeException('Duplicate transaction');
        }

        try {
            RequestEvents::emitBeforeSendEvent($transaction);
        } catch (RequestException $e) {
            $this->throwBatchException($e);
        }

        // Only transfer if the request was not intercepted
        if (!$transaction->getResponse()) {
            $handle = $this->curlFactory->createHandle(
                $transaction,
                $this->messageFactory
            );
            $this->checkCurlMultiResult(curl_multi_add_handle($this->multi, $handle));
            $this->handles[$transaction] = $handle;
        }
    }

    private function removeTransaction(TransactionInterface $transaction)
    {
        curl_multi_remove_handle($this->multi, $this->handles[$transaction]);
        curl_close($this->handles[$transaction]);
        unset($this->handles[$transaction]);
    }

    /**
     * Check if a cURL transfer resulted in what should be an exception
     *
     * @param TransactionInterface $transaction Request to check
     * @param array                $curl        Array returned from curl_multi_info_read
     *
     * @return bool
     * @throws RequestException|bool
     */
    private function isCurlException(TransactionInterface $transaction, array $curl)
    {
        if (CURLM_OK == $curl['result'] || CURLM_CALL_MULTI_PERFORM == $curl['result']) {
            return false;
        }

        $request = $transaction->getRequest();
        try {
            RequestEvents::emitErrorEvent($transaction, new RequestException(
                sprintf(
                    '[curl] (#%s) %s [url] %s',
                    $curl['result'],
                    function_exists('curl_strerror')
                        ? curl_strerror($curl['result'])
                        : self::ERROR_STR,
                    $request->getUrl()
                ),
                $request
            ));
        } catch (RequestException $e) {
            $this->throwBatchException($e);
        }

        return true;
    }

    /**
     * Throw an exception for a cURL multi response if needed
     *
     * @param int $code Curl response code
     * @throws AdapterException
     */
    private function checkCurlMultiResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            $buffer = function_exists('curl_multi_strerror')
                ? curl_multi_strerror($code)
                : self::ERROR_STR;
            throw new AdapterException(sprintf('cURL error %s: %s', $code, $buffer));
        }
    }

    private function throwBatchException(RequestException $e)
    {
        // Remove any pending transactions to keep a consistent state
        while (curl_multi_info_read($this->multi));

        $remaining = [];
        foreach ($this->handles as $transaction) {
            $this->removeTransaction($transaction);
            if ($transaction->getRequest() !== $e->getRequest()) {
                $remaining[] = $transaction;
            }
        }

        // Create an iterator that contains the incomplete transactions
        $iterator = new \AppendIterator();

        if ($remaining) {
            $iterator->append(new \ArrayIterator($remaining));
        }

        if ($this->transactions->valid()) {
            $iterator->append(new \NoRewindIterator($this->transactions));
        }

        $this->transactions = null;

        throw new BatchException(
            $e->getMessage(),
            $e->getRequest(),
            $e->getResponse(),
            $e->getPrevious(),
            $iterator
        );
    }
}
