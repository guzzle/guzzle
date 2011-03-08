<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Adapter;

/**
 * Adapts the Zend_Log class to the Guzzle framework
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ZendLogAdapter extends AbstractLogAdapter
{
    /**
     * {@inheritdoc}
     */
    protected $className = 'Zend_Log';

    /**
     * {@inheritdoc}
     */
    protected function logMessage($message, $priority = self::INFO, $category = null, $host = null)
    {
        $compiledMessage = '';
        if ($host) {
            $compiledMessage .= "[{$host}] ";
        }
        if ($category) {
            $compiledMessage .= "[{$category}] ";
        }
        $compiledMessage .= $message;
        
        $this->log->log($compiledMessage, $priority);
    }
}