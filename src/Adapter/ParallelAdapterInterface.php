<?php

namespace GuzzleHttp\Adapter;

/**
 * Adapter interface used to transfer multiple HTTP requests in parallel.
 */
interface ParallelAdapterInterface
{
    /**
     * Transfers multiple HTTP requests in parallel.
     *
     * RequestExceptions MUST not be thrown from a parallel transfer.
     *
     * @param \Iterator $transactions Iterable of TransactionInterface objects
     * @param int       $parallel     Maximum number of requests to send in parallel
     */
    public function sendAll(\Iterator $transactions, $parallel);
}
