<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Adapter;

use Guzzle\Service\Aws\SimpleDb\SimpleDbException;
use Guzzle\Service\Aws\SimpleDb\Command\PutAttributes;
use Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes;

/**
 * Adds queueing functionality to a logger so that messages are only written
 * when flush is called, __destruct() is called, or the items in the queue reach
 * the $_maxQueueSize.  Pass a configuration parameter of
 * 'implicit_flush' => TRUE to disable batching.
 *
 * @author Michael Dowling <michael@guzzle-project.org>
 * @codeCoverageIgnore
 */
abstract class AbstractQueuedLogAdapter extends AbstractLogAdapter
{
    /**
     * @var array An array of queued log messages
     */
    protected $queued = array();

    /**
     * @var integer The maximum number of items that can be queued before flushing
     */
    protected $maxQueueSize = 30;

    /**
     * Writes any commands that are queued but haven't been sent
     *
     * @return void
     */
    public function __destruct()
    {
        $this->flush();
    }

    /**
     * Set the maximum number if items that can be queued before a flush is
     * made
     *
     * @param integer $maxQueueSize Maximum number of queued items
     *
     * @return AbstractQueuedLogAdapter
     */
    public function setMaxQueueSize($maxQueueSize)
    {
        $this->maxQueueSize = max(1, (int) $maxQueueSize);

        return $this;
    }

    /**
     * Flush any queued log messages and send them to the logger
     *
     * @return integer Returns the number of queued messages that were sent
     */
    abstract public function flush();
}