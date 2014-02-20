<?php

namespace GuzzleHttp\Adapter\Curl;

use GuzzleHttp\Adapter\AdapterInterface;
use GuzzleHttp\Adapter\ParallelAdapterInterface;
use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\AdapterException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactoryInterface;

class AbstractCurlAdapter implements AdapterInterface, ParallelAdapterInterface
{
    const ERROR_STR = 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html for an explanation of cURL errors';

    /** @var CurlFactory */
    private $curlFactory;

    /** @var MessageFactoryInterface */
    private $messageFactory;

    /** @var array Array of curl multi handles */
    private $multiHandles = [];

    /** @var array Array of curl multi handles */
    private $multiOwned = [];

    /** @var double */
    private $selectTimeout;

    /**
     * @param MessageFactoryInterface $messageFactory
     * @param array                   $options Array of options to use with the adapter
     *     - handle_factory: Optional factory used to create cURL handles
     *     - select_timeout: Specify a float in seconds to use for a curl_multi_select timeout.
     */
    public function __construct(MessageFactoryInterface $messageFactory, array $options = [])
    {
        $this->handles = new \SplObjectStorage();
        $this->messageFactory = $messageFactory;
        $this->curlFactory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory();
        $this->selectTimeout = isset($options['select_timeout'])
            ? $options['select_timeout']
            : 1;
    }

    public function __destruct()
    {
        foreach ($this->multiHandles as $handle) {
            if (is_resource($handle)) {
                curl_multi_close($handle);
            }
        }
    }

    /**
     * Throw an exception for a cURL multi response
     *
     * @param int $code Curl response code
     * @throws AdapterException
     */
    public static function throwMultiError($code)
    {
        $buffer = function_exists('curl_multi_strerror')
            ? curl_multi_strerror($code)
            : self::ERROR_STR;

        throw new AdapterException(sprintf('cURL error %s: %s', $code, $buffer));
    }

    public function send(TransactionInterface $transaction)
    {
        $context = new BatchContext($this->checkoutMultiHandle(), true);
        $this->addHandle($transaction, $context);
        $this->perform($context);

        return $transaction->getResponse();
    }

    public function sendAll(\Iterator $transactions, $parallel)
    {
        $context = new BatchContext(
            $this->checkoutMultiHandle(),
            false,
            $transactions
        );

        $total = 0;
        while ($transactions->valid() && $total < $parallel) {
            $current = $transactions->current();
            $this->addHandle($current, $context);
            $total++;
            $transactions->next();
        }

        $this->perform($context);
    }

    private function perform(BatchContext $context)
    {
        // The first curl_multi_select often times out no matter what, but is usually required for fast transfers
        $active = false;
        $multi = $context->getMultiHandle();

        do {
            while (($mrc = curl_multi_exec($multi, $active)) == CURLM_CALL_MULTI_PERFORM);
            if ($mrc != CURLM_OK && $mrc != CURLM_CALL_MULTI_PERFORM) {
                self::throwMultiError($mrc);
            }
            $this->processMessages($context);
            if ($active && curl_multi_select($multi, $this->selectTimeout) === -1) {
                // Perform a usleep if a select returns -1: https://bugs.php.net/bug.php?id=61141
                usleep(250);
            }
        } while ($active || $context->hasPending());

        $this->releaseMultiHandle($context->getMultiHandle());
    }

    private function processMessages(BatchContext $context)
    {
        $multi = $context->getMultiHandle();

        while ($done = curl_multi_info_read($multi)) {
            if ($transaction = $context->findTransaction($done['handle'])) {
                $this->processResponse($transaction, $done, $context);
                // Add the next transaction if there are more in the queue
                if ($next = $context->nextPending()) {
                    $this->addHandle($next, $context);
                }
            }
        }
    }

    private function processResponse(
        TransactionInterface $transaction,
        array $curl,
        BatchContext $context
    ) {
        $handle = $context->removeTransaction($transaction);

        try {
            if (!$this->isCurlException($transaction, $curl, $context)) {
                RequestEvents::emitComplete($transaction, curl_getinfo($handle));
            }
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }
    }

    private function addHandle(TransactionInterface $transaction, BatchContext $context)
    {
        try {
            RequestEvents::emitBefore($transaction);
            // Only transfer if the request was not intercepted
            if (!$transaction->getResponse()) {
                try {
                    $handle = $this->curlFactory->createHandle(
                        $transaction,
                        $this->messageFactory
                    );
                    $context->addTransaction($transaction, $handle);
                } catch (RequestException $e) {
                    RequestEvents::emitError($transaction, $e);
                }
            }
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }
    }

    private function isCurlException(
        TransactionInterface $transaction,
        array $curl,
        BatchContext $context
    ) {
        if (CURLM_OK == $curl['result'] || CURLM_CALL_MULTI_PERFORM == $curl['result']) {
            return false;
        }

        $request = $transaction->getRequest();
        try {
            // Send curl stats along if they are available
            $stats = ['curl_result' => $curl['result']];
            if (isset($curl['handle'])) {
                $stats = curl_getinfo($curl['handle']) + $stats;
            }
            RequestEvents::emitError(
                $transaction,
                new RequestException(
                    sprintf(
                        '[curl] (#%s) %s [url] %s',
                        $curl['result'],
                        function_exists('curl_strerror')
                            ? curl_strerror($curl['result'])
                            : self::ERROR_STR,
                        $request->getUrl()
                    ),
                    $request
                ),
                $stats
            );
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }

        return true;
    }

    private function throwException(RequestException $e, BatchContext $context)
    {
        if ($context->throwsExceptions()) {
            $this->releaseMultiHandle($context->getMultiHandle());
            throw $e;
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
}
