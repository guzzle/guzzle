<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Exception\RequestException;

/**
 * Decorates a regular AdapterInterface object and creates a
 * ParallelAdapterInterface object that sends multiple HTTP requests serially.
 */
class FakeParallelAdapter implements ParallelAdapterInterface
{
    /** @var AdapterInterface */
    private $adapter;

    /**
     * @param AdapterInterface $adapter Adapter used to send requests
     */
    public function __construct(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    public function sendAll(\Iterator $transactions, $parallel)
    {
        foreach ($transactions as $transaction) {
            try {
                $this->adapter->send($transaction);
            } catch (RequestException $e) {
                // no op for batch transaction
            }
        }
    }
}
