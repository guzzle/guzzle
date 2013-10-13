<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Message\ResponseInterface;

/**
 * Adapter interface used to transfer HTTP requests
 */
interface AdapterInterface
{
    /**
     * Transfers an HTTP request and populates a response
     *
     * @param TransactionInterface $transaction Transaction abject to populate
     *
     * @return ResponseInterface
     */
    public function send(TransactionInterface $transaction);
}
