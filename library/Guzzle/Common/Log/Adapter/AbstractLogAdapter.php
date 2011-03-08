<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Adapter;

use Guzzle\Common\Collection;
use Guzzle\Common\Filter\Chain;
use Guzzle\Common\Log\LogException;

/**
 * Adapter class that allows Guzzle to log dato to various logging
 * implementations so that you may use the log classes of your favorite
 * framework.
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractLogAdapter implements LogAdapterInterface
{
    /**
     * @var mixed Concrete wrapped log object
     */
    protected $log;

    /**
     * @var string Required interface that the wrapped log object must implement
     */
    protected $className;

    /**
     * @var Collection Configuration options to use with the log adapters
     */
    protected $config;

    /**
     * @var Chain Filter chain to filter log messages
     */
    protected $chain;

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
    public function __construct($logObject, $config = null, Chain $chain = null)
    {
        if ($this->className) {
            if (!($logObject instanceof $this->className)) {
                throw new LogAdapterException('The wrapped log object must implement ' . $this->className);
            }
        }
        $this->log = $logObject;

        if ($config instanceof Collection) {
            $this->config = $config;
        } else {
            $this->config = new Collection($config ?: array());
        }

        if ($chain) {
            $this->chain = $chain->setBreakOnProcess(true);
        } else {
            $this->chain = new Chain(null, true);
        }

        $this->init();
    }

    /**
     * Proxy calls to the concrete log object
     *
     * @param string $method Name of the method to proxy
     * @param array $args (optional) Arguments to pass to the method
     *
     * @return mixed Returns the result of the proxied method call
     *
     * @throws \BadMethodCallException if the method is not found on the
     *      concrete log object.
     */
    public function __call($method, array $args = null)
    {
        if (method_exists($this->log, $method)) {
            return call_user_func_array(array($this->log, $method), $args);
        } else {
            throw new \BadMethodCallException('Call to undefined method ' . $method);
        }
    }

    /**
     * Get the log filter chain for filtering log messages
     *
     * @return Chain
     */
    public function getFilterChain()
    {
        return $this->chain;
    }

    /**
     * Get the wrapped log object
     *
     * @return mixed
     */
    public function getLogObject()
    {
        return $this->log;
    }

    /**
     * Log a message at a priority
     *
     * @param string $message Message to log
     * @param integer $priority (optional) Priority of message
     * @param string $category (optional) Component that this log message relates to
     * @param string $host (optional) The host that logged the message
     *
     * @return AbstractLogAdapter
     */
    public function log($message, $priority = \LOG_INFO, $category = null, $host = null)
    {
        $chain = $this->getFilterChain();
        
        if (!count($chain) || $chain->allTrue(array(
            'message' => $message,
            'priority' => $priority,
            'category' => $category
        ))) {
            $this->logMessage($message, $priority, $category, $host);
        }
        
        return $this;
    }

    /**
     * Inialization hook for subclasses
     *
     * @codeCoverageIgnore
     */
    protected function init()
    {
        return;
    }

    /**
     * Log a message at a priority
     *
     * @param string $message Message to log
     * @param integer $priority (optional) Priority of message
     * @param string $category (optional) Category that this log message relates to
     * @param string $host (optional) The host that logged the message
     *
     * @return AbstractLogAdapter
     */
    abstract protected function logMessage($message, $priority = \LOG_INFO, $category = null, $host = null);
}