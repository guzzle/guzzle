<?php

namespace Guzzle\Common\Log;

/**
 * Adapter class that allows Guzzle to log data to various logging
 * implementations so that you may use the log classes of your favorite
 * framework.
 */
abstract class AbstractLogAdapter implements LogAdapterInterface
{
    protected $log;

    /**
     * {@inheritdoc}
     */
    public function getLogObject()
    {
        return $this->log;
    }
}
