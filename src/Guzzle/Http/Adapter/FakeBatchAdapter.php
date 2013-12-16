<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Exception\BatchException;

/**
 * Decorates a regular adapter and creates a batch adapter that sends multiple
 * requests serially
 */
class FakeBatchAdapter implements BatchAdapterInterface
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

    public function batch(\Iterator $transactions, $parallel)
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
