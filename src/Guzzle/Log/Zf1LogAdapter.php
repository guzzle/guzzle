<?php

namespace Guzzle\Log;

/**
 * Adapts a Zend Framework 1 logger object
 * @deprecated
 */
class Zf1LogAdapter extends AbstractLogAdapter
{
    /**
     * {@inheritdoc}
     */
    public function __construct(\Zend_Log $logObject)
    {
        $this->log = $logObject;
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        $this->log->log($message, $priority, $extras);
    }
}
