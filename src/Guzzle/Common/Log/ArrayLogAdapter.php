<?php

namespace Guzzle\Common\Log;

use Guzzle\Common\Log\LogAdapterInterface;

/**
 * Adapter class that allows Guzzle to log data to various logging
 * implementations so that you may use the log classes of your favorite
 * framework.
 */
class ArrayLogAdapter implements LogAdapterInterface
{
    protected $logs = array();

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        $this->logs[] = array('message' => $message, 'priority' => $priority, 'extras' => $extras);
    }

    /**
     * Get logged entries
     *
     * @return array
     */
    public function getLogs()
    {
        return $this->logs;
    }

    /**
     * Clears logged entries
     */
    public function clearLogs()
    {
        $this->logs = array();
    }
}
