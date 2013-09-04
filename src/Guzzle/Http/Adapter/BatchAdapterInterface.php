<?php

namespace Guzzle\Http\Adapter;

/**
 * Adapter interface used to transfer HTTP requests
 */
interface BatchAdapterInterface
{
    /**
     * Transfers multiple HTTP requests in parallel
     *
     * @param array $transactions Array of TransactionInterface objects
     */
    public function batch(array $transactions);
}
