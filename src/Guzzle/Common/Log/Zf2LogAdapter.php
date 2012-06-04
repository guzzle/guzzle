<?php

namespace Guzzle\Common\Log;

use Zend\Log\Logger;

/**
 * Adapts a Zend Framework 2 logger object
 */
class Zf2LogAdapter extends AbstractLogAdapter
{
    /**
     * {@inheritdoc}
     */
    public function __construct(Logger $logObject)
    {
        $this->log = $logObject;
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        $this->log->log($priority, $message, $extras ?: array());
    }
}
