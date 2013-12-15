<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\BatchAdapterInterface;
use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Exception\AdapterException;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;

/**
 * HTTP adapter that uses cURL as a transport layer
 */
class CurlAdapter implements AdapterInterface, BatchAdapterInterface
{
    const ERROR_STR = 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html for an explanation of cURL errors';

    /** @var CurlFactory */
    private $curlFactory;
    /** @var array Array of curl multi handles */
    private $multiHandles = array();
    /** @var array Array of curl multi handles */
    private $multiOwned = array();
    /** @var MessageFactoryInterface */
    private $messageFactory;

    /**
     * @param MessageFactoryInterface $messageFactory
     * @param array                   $options Array of options to use with the adapter
     *                                         - handle_factory: Optional factory used to create cURL handles
     */
    public function __construct(MessageFactoryInterface $messageFactory, array $options = [])
    {
        $this->messageFactory = $messageFactory;
        $this->curlFactory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory();
    }

    /**
     * Destroys each open multi handle
     */
    public function __destruct()
    {
        foreach ($this->multiHandles as $handle) {
            if (is_resource($handle)) {
                curl_multi_close($handle);
            }
        }
    }

    /**
     * Throw an exception for a cURL multi response if needed
     *
     * @param int $code Curl response code
     * @throws AdapterException
     */
    public static function checkCurlMultiResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            $buffer = function_exists('curl_multi_strerror') ? curl_multi_strerror($code) : self::ERROR_STR;
            throw new AdapterException(sprintf('cURL error %s: %s', $code, $buffer));
        }
    }

    public function send(TransactionInterface $transaction)
    {
        $this->batch([$transaction]);

        return $transaction->getResponse();
    }

    public function batch($transactions, $parallel = 50)
    {
        if (is_array($transactions)) {
            $transactions = new \ArrayIterator($transactions);
        } elseif (!($transactions instanceof \Traversable)) {
            throw new \InvalidArgumentException('Transactions must be an array or \Iterator');
        }

        $context = new CurlTransactionQueue(
            $transactions,
            $this->checkoutMultiHandle(),
            $this->curlFactory,
            $this->messageFactory
        );

        $total = 0;
        while ($transactions->valid() && $total < $parallel) {
            $current = $transactions->current();
            try {
                $context->addTransaction($current);
                $total++;
            } catch (RequestException $e) {
                $this->onError($current, $e, $context, ['curl_context' => $context]);
            }
            $transactions->next();
        }

        $this->perform($context);
        $this->releaseMultiHandle($context->getMultiHandle());
    }

    /**
     * Execute and select curl handles
     */
    private function perform(CurlTransactionQueue $context)
    {
        // The first curl_multi_select often times out no matter what, but is usually required for fast transfers
        $selectTimeout = 0.001;
        $active = false;
        $multi = $context->getMultiHandle();

        do {
            while (($mrc = curl_multi_exec($multi, $active)) == CURLM_CALL_MULTI_PERFORM);
            self::checkCurlMultiResult($mrc);
            $this->processMessages($context);
            if ($active && curl_multi_select($multi, $selectTimeout) === -1) {
                // Perform a usleep if a select returns -1: https://bugs.php.net/bug.php?id=61141
                usleep(150);
            }
            $selectTimeout = 1;
        } while ($active || $context->getActiveCount());
    }

    /**
     * Check for errors and fix headers of a request based on a curl response
     * @throws RequestException on error
     */
    private function processResponse(TransactionInterface $transaction, array $curl, CurlTransactionQueue $context)
    {
        $handle = $context->getTransactionResource($transaction);
        $stats = curl_getinfo($handle);
        $stats['curl_context'] = $context;

        try {
            $context->removeTransaction($transaction);
        } catch (RequestException $e) {
            $this->onError($transaction, $e, $context, ['curl_context' => $context]);
        }

        $request = $transaction->getRequest();

        try {
            $this->isCurlException($request, $curl);
            $request->getEventDispatcher()->dispatch(
                RequestEvents::AFTER_SEND,
                new RequestAfterSendEvent($transaction, $stats)
            );
        } catch (RequestException $e) {
            $stats['curl_result'] = $curl['result'];
            $this->onError($transaction, $e, $context, $stats);
        }
    }

    /**
     * Check if a cURL transfer resulted in what should be an exception
     *
     * @param RequestInterface $request Request to check
     * @param array            $curl    Array returned from curl_multi_info_read
     *
     * @throws RequestException|bool
     */
    private function isCurlException(RequestInterface $request, array $curl)
    {
        if (CURLM_OK == $curl['result'] || CURLM_CALL_MULTI_PERFORM == $curl['result']) {
            return;
        }

        throw new RequestException(
            sprintf(
                '[curl] (#%s) %s [url] %s',
                $curl['result'],
                function_exists('curl_strerror') ? curl_strerror($curl['result']) : self::ERROR_STR,
                $request->getUrl()
            ),
            $request
        );
    }

    /**
     * Process any received curl multi messages
     */
    private function processMessages(CurlTransactionQueue $context)
    {
        $multi = $context->getMultiHandle();
        while ($done = curl_multi_info_read($multi)) {
            foreach ($context->getActiveTransactions() as $transaction) {
                if ($context->getTransactionResource($transaction) === $done['handle']) {
                    $this->processResponse($transaction, $done, $context);
                    continue 2;
                }
            }
        }
    }

    /**
     * Returns a curl_multi handle from the cache or creates a new one
     *
     * @return resource
     */
    private function checkoutMultiHandle()
    {
        // Find an unused handle in the cache
        if (false !== ($key = array_search(false, $this->multiOwned, true))) {
            $this->multiOwned[$key] = true;
            return $this->multiHandles[$key];
        }

        // Add a new handle
        $handle = curl_multi_init();
        $this->multiHandles[(int) $handle] = $handle;
        $this->multiOwned[(int) $handle] = true;

        return $handle;
    }

    /**
     * Releases a curl_multi handle back into the cache and removes excess cache
     *
     * @param resource $handle Curl multi handle to remove
     */
    private function releaseMultiHandle($handle)
    {
        $this->multiOwned[(int) $handle] = false;
        // Prune excessive handles
        $over = count($this->multiHandles) - 3;
        while (--$over > -1) {
            curl_multi_close(array_pop($this->multiHandles));
            array_pop($this->multiOwned);
        }
    }

    /**
     * Handle an error
     */
    private function onError(
        TransactionInterface $transaction,
        \Exception $e,
        CurlTransactionQueue $context,
        array $stats
    ) {
        if (!$transaction->getRequest()->getEventDispatcher()->dispatch(
            RequestEvents::ERROR,
            new RequestErrorEvent($transaction, $e, $stats)
        )->isPropagationStopped()) {
            // Clean up multi handles and context
            foreach ($context->getTransactions() as $trans) {
                $context->removeTransaction($trans);
            }
            $this->releaseMultiHandle($context->getMultiHandle());
            throw $e;
        }
    }
}
