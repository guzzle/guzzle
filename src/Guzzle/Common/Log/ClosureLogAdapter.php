<?php

namespace Guzzle\Common\Log;

/**
 * Allows Closures to be called when messages are logged.  Closures combined
 * with filtering can trigger application events based on log messages.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClosureLogAdapter extends AbstractLogAdapter
{
    /**
     * Adapt a callable function
     *
     * @param mixed $logObject Log object to adapt
     * @throws InvalidArgumentException if the $logObject is not callable
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

        return $this;
    }
}