<?php

namespace Guzzle\Common\Log;

/**
 * Adapter class that allows Guzzle to log dato to various logging
 * implementations so that you may use the log classes of your favorite
 * framework.
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractLogAdapter implements LogAdapterInterface
{
    /**
     * @var mixed Concrete wrapped log object
     */
    protected $log;

    /**
     * Get the wrapped log object
     *
     * @return mixed
     */
    public function getLogObject()
    {
        return $this->log;
    }
}