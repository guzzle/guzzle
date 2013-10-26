<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Message\ResponseInterface;

/**
 * Adapter interface used to transfer HTTP requests.
 *
 * An adapter has the following behavior:
 *
 * 1. The adapter MUST return a ResponseInterface object in a successful
 *    condition.
 * 2. When all of the headers of a response have been received for a request,
 *    the adapter MUST emit a request.response_headers event.
 * 3. The adapter MUST emit a request.after_send event when a request has
 *    completed sending.
 * 4. The adapter MUST emit a request.error event when an error occurs at any
 *    point-- whether it is preparing a request for transfer or processing the
 *    response of a request.
 * 5. After emitting the request.error event, the adapter MUST check if the
 *    transaction associated with the error was intercepted, meaning a response
 *    was associated with the event and the event's propagation was stopped.
 *    If the propagation of the event was not stopped, then the adapter MUST
 *    throw the exception. If the propagation was stopped, then the adapter
 *    MUST NOT throw the exception.
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
