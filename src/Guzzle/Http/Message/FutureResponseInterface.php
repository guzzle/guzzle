<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Adapter\AdapterInterface;

/**
 * Represents a response that has been acknowledged by an Async capable adapter that can later be sent in parallel
 * with other future response objects
 */
interface FutureResponseInterface extends ResponseInterface
{
    /**
     * Get the transaction object that should be transferred
     *
     * @return Transaction
     */
    public function getTransaction();

    /**
     * Get the HTTP adapter that should be used when transferring
     *
     * @return AdapterInterface
     */
    public function getAdapter();

    /**
     * Send the postponed HTTP request if it has not already sent
     *
     * @return self
     */
    public function send();
}
