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
     * @param TransactionInterface $transaction Transaction abject to populate
     */
    public function send(TransactionInterface $transaction);
}
