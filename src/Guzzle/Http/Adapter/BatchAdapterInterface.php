<?php

namespace Guzzle\Http\Adapter;

/**
 * Adapter interface used to transfer multiple HTTP requests
 */
interface BatchAdapterInterface extends AdapterInterface
{
    /**
     * Transfers multiple HTTP requests in parallel
     *
     * @param array|\Traversable $transactions Iterable of TransactionInterface objects
     * @param int                $parallel     Maximum number of requests to send in parallel
     */
    public function batch($transactions, $parallel = 50);
}
