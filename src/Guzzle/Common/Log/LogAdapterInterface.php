<?php

namespace Guzzle\Common\Log;

/**
 * Adapter class that allows Guzzle to log dato to various logging
 * implementations so that you may use the log classes of your favorite
 * framework.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface LogAdapterInterface
{
    /**
     * Create a new LogAdapter
     *
     * @param mixed $logObject (optional) The concrete logging implementation
     *      that will be wrapped by the adapter.
     *
     * @throws InvalidArgumentException if the supplied object does not implement the
     *      correct interface.
     */
    function __construct($logObject);

    /**
     * Get the adapted log object
     *
     * @return mixed
     */
    function getLogObject();

    /**
     * Log a message at a priority
     *
     * @param string $message Message to log
     * @param integer $priority (optional) Priority of message (use the \LOG_* constants of 0 - 7)
     * @param mixed $extras (optional) Extra information to log in event
     *
     * @return LogAdapterInterface
     */
    function log($message, $priority = LOG_INFO, $extras = null);
}