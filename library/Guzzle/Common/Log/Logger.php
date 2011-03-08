<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log;

use Guzzle\Common\Log\Adapter\LogAdapterInterface;

/**
 * Logs data to multiple {@see LogAdapterInterface} objects.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Logger
{
    /**
     * Array of log adapters which are responsible for writing log data.
     *
     * @var array[int]LogAdapterInterface
     */
    protected $adapters = array();

    /**
     * @var array Array of adapter class to adapter mappings
     */
    protected $classMap = array(
        'Zend_Log' => 'Guzzle\Common\Log\Adapter\ZendLogAdapter'
    );

    /**
     * Constructor
     *
     * @param array[int]LogAdapterInterface $adapters An array of
     *      {@see LogAdapterInterface} objects which are responsible for
     *      communicating with concrete log objects.
     */
    public function __construct(array $adapters = null)
    {
        if ($adapters) {
            foreach ($adapters as $adapter) {
                $this->addAdapter($adapter);
            }
        }
    }
    
    /**
     * Add an adapter to the logger.
     *
     * This method will accept a LogAdapterInterface or a log object that the
     * Logger can generate an adapter for automatically.
     * {@see Logger::classMap} for a list of known adapter mappings.
     *
     * @param mixed $adapter Adapter to add or log object to add
     * @param mixed $config (optional) If adding the adapter using the factory,
     *      then pass an optional array or Collection of config options
     * @param mixed $filterChain (optional) If adding the adapter using the
     *      factory, then pass an optional Chain
     *
     * @return LogAdapterInterface
     *
     * @throws LogException if the adapter cannot be added
     */
    public function addAdapter($adapter, $config = null, Chain $filterChain = null)
    {
        if (!($adapter instanceof LogAdapterInterface)) {
            foreach ($this->classMap as $match => $map) {
                if ($adapter instanceof $match) {
                    $adapter = new $map($adapter, $config, $filterChain);
                    break;
                }
            }
        }

        if ($adapter instanceof LogAdapterInterface) {
            $this->adapters[] = $adapter;
        } else {
            throw new LogException('Object of type ' . get_class($adapter) . ' could not be added as a LogAdapterInterface');
        }

        return $adapter;
    }

    /**
     * Get the log adapters
     *
     * @return array[int]LogAdapterInterface
     */
    public function getAdapters()
    {
        return $this->adapters;
    }

    /**
     * Remove a log adapter
     *
     * @param LogAdapterInterface $adapter Log adapter to remove
     *
     * @return LogAdapterInterface Returns the adapter that was either
     *      removed or attempted to be removed
     */
    public function removeAdapter(LogAdapterInterface $adapter)
    {
        $this->adapters = array_values(array_filter($this->adapters, function($value) use ($adapter) {
            return !($value === $adapter);
        }));
        
        return $adapter;
    }

    /**
     * Log a message at a priority
     *
     * @param string $message Message to log
     * @param integer $priority (optional) Priority of message
     * @param string $category (optional) Categorization of the message
     * @param string $host (optional) Host that issued the log message
     *
     * @return Logger Provides a fluent interface
     */
    public function log($message, $priority = \LOG_INFO, $category = '', $host = '')
    {
        if (is_string($message)) {
            foreach ($this->adapters as $adapter) {
                $adapter->log($message, $priority, $category, $host);
            }
        }
        
        return $this;
    }
}