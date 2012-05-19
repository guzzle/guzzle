<?php

namespace Guzzle\Http\Curl;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Exception\ExceptionCollection;
use Guzzle\Http\Message\RequestInterface;

/**
 * Execute a pool of {@see RequestInterface} objects in
 * parallel.
 */
interface CurlMultiInterface extends HasDispatcherInterface, \Countable
{
    const BEFORE_SEND = 'curl_multi.before_send';
    const POLLING = 'curl_multi.polling';
    const POLLING_REQUEST = 'curl_multi.polling_request';
    const COMPLETE = 'curl_multi.complete';
    const ADD_REQUEST = 'curl_multi.add_request';
    const REMOVE_REQUEST = 'curl_multi.remove_request';
    const MULTI_EXCEPTION = 'curl_multi.exception';

    const STATE_IDLE = 'idle';
    const STATE_SENDING = 'sending';
    const STATE_COMPLETE = 'complete';

    /**
     * Add a request to the pool.
     *
     * @param RequestInterface $request Returns the Request that was added
     *
     * @return CurlMultiInterface
     */
    function add(RequestInterface $request);

    /**
     * Get an array of attached {@see RequestInterface}s.
     *
     * @return array Returns an array of attached requests.
     */
    function all();

    /**
     * Get the current state of the Pool
     *
     * @return string
     */
    function getState();

    /**
     * Remove a request from the pool.
     *
     * @param RequestInterface $request Request to detach.
     *
     * @return CurlMultiInterface
     */
    function remove(RequestInterface $request);

    /**
     * Reset the state of the multi and remove any attached RequestInterface objects
     *
     * @param bool $hard Set to TRUE to close any open multi handles
     */
    function reset($hard = false);

    /**
     * Send a pool of {@see RequestInterface} requests.
     *
     * Calling this method more than once will return FALSE.
     *
     * @return array|bool Returns an array of attached Request objects on
     *                    success FALSE on failure.
     * @throws ExceptionCollection if any requests threw exceptions during the
     *                             transfer.
     */
    function send();
}
