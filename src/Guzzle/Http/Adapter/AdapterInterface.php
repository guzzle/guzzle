<?php

namespace Guzzle\Http\Adapter;

/**
 * Adapter interface used to transfer HTTP requests
 */
interface AdapterInterface
{
    /**
     * Transfers an HTTP request and populates a response
     *
     * @param Transaction $transaction Transaction abject to populate
     *
     * @return Transaction
     */
    public function send(Transaction $transaction);
}
