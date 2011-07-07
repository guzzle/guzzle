<?php

namespace Guzzle\Common\Log;

/**
 * Adapts the Zend_Log class to the Guzzle framework
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ZendLogAdapter extends AbstractLogAdapter
{
    /**
     * Adapt a Zend_Log object
     * 
     * @param Zend_Log $logObject Log object to adapt
     * @throws InvalidArgumentException
     */
    public function __construct($logObject) 
    {
        if (!($logObject instanceof \Zend_Log)) {
            throw new \InvalidArgumentException(
                'Object must be an instance of Zend_Log'
            );
        }

        $this->log = $logObject;
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        $this->log->log($message, $priority, $extras);

        return $this;
    }
}