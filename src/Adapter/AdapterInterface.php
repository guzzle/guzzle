<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\Message\ResponseInterface;

/**
 * Adapter interface used to transfer HTTP requests.
 *
 * An adapter has the following behavior:
 *
 * 1. The adapter MUST return a ResponseInterface object in a successful
 *    condition.
 * 2. The adapter MUST emit a ``before`` event before sending a request. If a
 *    response is associated with a transaction after preparing, then the
 *    adapter MUST not re-send the
 * 3. When all of the headers of a response have been received for a request,
 *    the adapter MUST emit a ``headers`` event.
 * 4. The adapter MUST emit a ``complete`` event when a request has completed
 *    sending.
 * 5. The adapter MUST emit an ``error`` event when an error occurs at any
 *    point-- whether it is preparing a request for transfer or processing the
 *    response of a
 * 5. After emitting the error event, the adapter MUST check if the
 *    transaction associated with the error was intercepted, meaning a response
 *    was associated with the event and the event's propagation was stopped.
 *    If the propagation of the event was not stopped, then the adapter MUST
 *    throw the exception. If the propagation was stopped, then the adapter
 *    MUST NOT throw the exception.
 *
 * Adapter must also handle request options that are used to modify how a
 * request is sent over the wire. The following request options MUST be handled
 * by an adapter:
 *
 * - cert
 * - connect_timeout
 * - debug
 * - expect
 * - proxy
 * - save_to
 * - ssl_key
 * - stream
 * - timeout
 * - verify
 *
 * {@see GuzzleHttp\MessageFactoryInterface} for a full description of the
 * format and expected behavior of each option.
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
