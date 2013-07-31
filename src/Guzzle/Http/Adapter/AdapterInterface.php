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
     * @param array $requests Array of {@see \Guzzle\Http\Message\RequestInterface} objects
     *
     * @return Transaction Returns a hash mapping RequestInterface to ResponseInterface objects or AdapterExceptions
     */
    public function send(array $requests);
}
