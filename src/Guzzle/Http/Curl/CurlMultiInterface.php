<?php

namespace Guzzle\Http\Curl;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Exception\ExceptionCollection;
use Guzzle\Http\Message\RequestInterface;

/**
 * Interface for sending a pool of {@see RequestInterface} objects in parallel
 */
interface CurlMultiInterface extends HasDispatcherInterface, \Countable
{
    const BEFORE_SEND = 'curl_multi.before_send';
    const POLLING_REQUEST = 'curl_multi.polling_request';
    const COMPLETE = 'curl_multi.complete';
    const ADD_REQUEST = 'curl_multi.add_request';
    const REMOVE_REQUEST = 'curl_multi.remove_request';
    const MULTI_EXCEPTION = 'curl_multi.exception';
    const BLOCKING = 'curl_multi.blocking';

    const STATE_IDLE = 'idle';
    const STATE_SENDING = 'sending';
    const STATE_COMPLETE = 'complete';

    /**
     * Add a request to the pool.
     *
     * @param RequestInterface $request Request to add
     *
     * @return CurlMultiInterface
     */
    public function add(RequestInterface $request);

    /**
     * Get an array of attached {@see RequestInterface} objects
     *
     * @return array
     */
    public function all();

    /**
     * Get the current state of the Pool
     *
     * @return string
     */
    public function getState();

    /**
     * Remove a request from the pool.
     *
     * @param RequestInterface $request Request to remove
     *
     * @return CurlMultiInterface
     */
    public function remove(RequestInterface $request);

    /**
     * Reset the state and remove any attached RequestInterface objects
     *
     * @param bool $hard Set to true to close and reopen any open multi handles
     */
    public function reset($hard = false);

    /**
     * Send a pool of {@see RequestInterface} requests.
     *
     * @throws ExceptionCollection if any requests threw exceptions during the transfer.
     */
    public function send();
}
