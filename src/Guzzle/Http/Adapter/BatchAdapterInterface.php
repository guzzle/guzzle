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
     * @param array $transactions Array of TransactionInterface objects
     */
    public function batch(array $transactions);
}
