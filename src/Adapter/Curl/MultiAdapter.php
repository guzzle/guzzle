<?php

namespace GuzzleHttp\Adapter\Curl;

use GuzzleHttp\Adapter\AdapterInterface;
use GuzzleHttp\Adapter\ParallelAdapterInterface;
use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\AdapterException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactoryInterface;

/**
 * HTTP adapter that uses cURL multi as a transport layer
 *
 * When using the CurlAdapter, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of a request's configuration options.
 */
class MultiAdapter implements AdapterInterface, ParallelAdapterInterface
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
     * @param array $options Array of options to use with the adapter:
     *     - handle_factory: Optional factory used to create cURL handles
     *     - select_timeout: Specify a float in seconds to use for a
     *       curl_multi_select timeout.
     */
    public function __construct(
        MessageFactoryInterface $messageFactory,
        array $options = []
    ) {
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

        foreach (new \LimitIterator($transactions, 0, $parallel) as $trans) {
            $this->addHandle($trans, $context);
        }

        $this->perform($context);
    }

    private function perform(BatchContext $context)
    {
        // The first curl_multi_select often times out no matter what, but is
        // usually required for fast transfers.
        $active = false;
        $multi = $context->getMultiHandle();

        do {
            while (($mrc = curl_multi_exec($multi, $active)) == CURLM_CALL_MULTI_PERFORM);
            if ($mrc != CURLM_OK && $mrc != CURLM_CALL_MULTI_PERFORM) {
                self::throwMultiError($mrc);
            }
            $this->processMessages($context);
            if ($active && curl_multi_select($multi, $this->selectTimeout) === -1) {
                // Perform a usleep if a select returns -1.
                // See: https://bugs.php.net/bug.php?id=61141
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
        $info = $context->removeTransaction($transaction);

        try {
            if (!$this->isCurlException($transaction, $curl, $context, $info)) {
                RequestEvents::emitComplete($transaction, $info);
            }
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }
    }

    private function addHandle(
        TransactionInterface $transaction,
        BatchContext $context
    ) {
        try {
            RequestEvents::emitBefore($transaction);
            // Only transfer if the request was not intercepted
            if (!$transaction->getResponse()) {
                $handle = $this->curlFactory->createHandle(
                    $transaction,
                    $this->messageFactory
                );
                $context->addTransaction($transaction, $handle);
            }
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }
    }

    private function isCurlException(
        TransactionInterface $transaction,
        array $curl,
        BatchContext $context,
        array $info
    ) {
        if (CURLM_OK == $curl['result'] ||
            CURLM_CALL_MULTI_PERFORM == $curl['result']
        ) {
            return false;
        }

        $request = $transaction->getRequest();
        try {
            // Send curl stats along if they are available
            $stats = ['curl_result' => $curl['result']] + $info;
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
        $key = array_search(false, $this->multiOwned, true);
        if (false !== $key) {
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
        $id = (int) $handle;

        if (count($this->multiHandles) <= 3) {
            $this->multiOwned[$id] = false;
        } else {
            // Prune excessive handles
            curl_multi_close($this->multiHandles[$id]);
            unset($this->multiHandles[$id], $this->multiOwned[$id]);
        }
    }
}
