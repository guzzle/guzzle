<?php

namespace Guzzle\Http\Adapter;

/**
 * Adapter interface used to transfer multiple HTTP requests
 */
interface BatchAdapterInterface
{
    /**
     * Transfers multiple HTTP requests in parallel.
     *
     * RequestExceptions MUST not be thrown from a batch transfer.
     *
     * @param \Iterator $transactions Iterable of TransactionInterface objects
     * @param int       $parallel     Maximum number of requests to send in parallel
     */
    public function batch(\Iterator $transactions, $parallel);
}
