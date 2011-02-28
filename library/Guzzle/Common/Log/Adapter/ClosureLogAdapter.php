<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Adapter;

/**
 * Allows Closures to be called when messages are logged.  Closures combined
 * with filtering can trigger application events based on log messages.
 *
 * @author Michael Dowling <michael@guzzle-project.org>
 */
class ClosureLogAdapter extends AbstractLogAdapter
{
    /**
     * {@inheritdoc}
     */
    protected $className = 'Closure';

    /**
     * {@inheritdoc}
     */
    protected function logMessage($message, $priority = self::INFO, $category = null, $host = null)
    {
        call_user_func($this->log, $message, $priority, $category, $host);
    }
}