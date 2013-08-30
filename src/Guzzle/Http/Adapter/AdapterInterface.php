<?php

namespace Guzzle\Http\Adapter;

/**
 * Adapter interface used to transfer HTTP requests
 */
interface AdapterInterface
{
    /**
     * Transfers one or more HTTP requests and populates responses
     *
     * @param Transaction $transaction Transaction abject to populate
     *
     * @return Transaction
     */
    public function send(Transaction $transaction);
}
