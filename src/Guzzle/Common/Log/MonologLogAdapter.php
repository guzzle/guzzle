<?php

namespace Guzzle\Common\Log;

use Monolog\Logger;

/**
 * Monolog log adapter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 * @link https://github.com/Seldaek/monolog
 */
class MonologLogAdapter extends AbstractLogAdapter
{
    /**
     * Adapt a Monolog Logger object
     *
     * @param Logger $logObject Log object to adapt
     * @throws InvalidArgumentException
     */
    public function __construct($logObject)
    {
        if (!($logObject instanceof Logger)) {
            throw new \InvalidArgumentException(
                'Object must be an instance of Monolog\Logger'
            );
        }

        $this->log = $logObject;
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $priority = LOG_INFO, $extras = null)
    {
        $this->log->addRecord($priority, $message);

        return $this;
    }
}