<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Adapter;

use Guzzle\Common\Filter\Chain;

/**
 * Adapter class that allows Guzzle to log dato to various logging
 * implementations so that you may use the log classes of your favorite
 * framework.
 *
 * @author Michael Dowling <michael@guzzle-project.org>
 */
interface LogAdapterInterface
{
    /**
     * Create a new LogAdapter
     *
     * @param object $logObject (optional) The concrete logging implementation
     *      that will be wrapped by the adapter.
     * @param array|Collection $config (optional) Configuration options to the
     *      use the concrete log adapter
     * @param Chain $chain (optional) Chain of filters to filter log messages
     *
     * @throws LogAdapterException if the supplied
     *      object does not implement the correct interface.
     */
    public function __construct($logObject, $config = null, Chain $chain = null);

    /**
     * Get the log filter chain for filtering log messages
     *
     * @return Chain
     */
    public function getFilterChain();

    /**
     * Get the wrapped log object
     *
     * @return mixed
     */
    public function getLogObject();

    /**
     * Log a message at a priority
     *
     * @param string $message Message to log
     * @param integer $priority (optional) Priority of message (use the \LOG_* constants of 0 - 7)
     * @param string $category (optional) Category that this log message relates to
     * @param string $host (optional) The host that logged the message
     *
     * @return LogAdapterInterface Provides a fluent interface
     */
    public function log($message, $priority = \LOG_INFO, $category = null, $host = null);
}