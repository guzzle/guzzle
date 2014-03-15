<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\Message\ResponseInterface;

/**
 * Adapter interface used to transfer HTTP requests.
 *
 * @link http://docs.guzzlephp.org/en/guzzle4/adapters.html for a full
 *     explanation of adapters and their responsibilities.
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
