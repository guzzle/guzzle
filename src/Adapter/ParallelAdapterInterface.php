<?php

namespace GuzzleHttp\Adapter;

/**
 * Adapter interface used to transfer multiple HTTP requests in parallel.
 *
 * Parallel adapters follow the same rules as AdapterInterface except that
 * RequestExceptions are never thrown in a parallel transfer and parallel
 * adapters do not return responses.
 */
interface ParallelAdapterInterface
{
    /**
     * Transfers multiple HTTP requests in parallel.
     *
     * RequestExceptions MUST not be thrown from a parallel transfer.
     *
     * @param \Iterator $transactions Iterator that yields TransactionInterface
     * @param int       $parallel     Max number of requests to send in parallel
     */
    public function sendAll(\Iterator $transactions, $parallel);
}
