<?php

namespace Guzzle\Log;

/**
 * Logs messages using Closures. Closures combined with filtering can trigger application events based on log messages.
 */
class ClosureLogAdapter extends AbstractLogAdapter
{
    /**
     * {@inheritdoc}
     */
    public function __construct($logObject)
    {
        if (!is_callable($logObject)) {
            throw new \InvalidArgumentException('Object must be callable');
        }

        $this->log = $logObject;
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        call_user_func($this->log, $message, $priority, $extras);
    }
}
