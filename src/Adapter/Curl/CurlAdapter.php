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
 * HTTP adapter that uses cURL easy handles as a transport layer.
 *
 * Requires PHP 5.5+
 *
 * When using the CurlAdapter, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of a request's configuration options.
 */
class CurlAdapter implements AdapterInterface
{
    /** @var CurlFactory */
    private $curlFactory;

    /** @var MessageFactoryInterface */
    private $messageFactory;

    /** @var array Array of curl easy handles */
    private $handles = [];

    /** @var array Array of owned curl easy handles */
    private $ownedHandles = [];

    /**
     * @param MessageFactoryInterface $messageFactory
     * @param array                   $options Array of options to use with the adapter
     *     - handle_factory: Optional factory used to create cURL handles
     */
    public function __construct(MessageFactoryInterface $messageFactory, array $options = [])
    {
        $this->handles = [];
        $this->ownedHandles = [];
        $this->messageFactory = $messageFactory;
        $this->curlFactory = isset($options['handle_factory']) ? $options['handle_factory'] : new CurlFactory();
    }

    public function __destruct()
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                curl_close($handle);
            }
        }
    }

    public function send(TransactionInterface $transaction)
    {
        $handle = $this->beforeSend($transaction);
        curl_exec($handle);
        $this->releaseEasyHandle($handle);

        return $transaction->getResponse();
    }

    private function beforeSend(TransactionInterface $transaction)
    {
        return $this->curlFactory->createHandle(
            $transaction,
            $this->messageFactory,
            $this->checkoutEasyHandle()
        );
    }

    private function checkoutEasyHandle()
    {
        // Find an unused handle in the cache
        if (false !== ($key = array_search(false, $this->ownedHandles, true))) {
            $this->ownedHandles[$key] = true;
            return $this->handles[$key];
        }

        // Add a new handle
        $handle = curl_init();
        $id = (int) $handle;
        $this->handles[$id] = $handle;
        $this->ownedHandles[$id] = true;

        return $handle;
    }

    private function releaseEasyHandle($handle)
    {
        $id = (int) $handle;
        $this->ownedHandles[$id] = false;

        // Prune excessive handles
        if (count($this->ownedHandles) > 5) {
            curl_close($this->handles[$id]);
            unset($this->handles[$id]);
            unset($this->ownedHandles[$id]);
        }
    }
}
