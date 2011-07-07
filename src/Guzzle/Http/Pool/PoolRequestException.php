<?php

namespace Guzzle\Http\Pool;

use Guzzle\Http\Message\RequestException;
use Guzzle\Http\HttpException;

/**
 * Pool exception that serves as a container for for any
 * {@see Guzzle\Http\Message\RequestInterface} request exceptions encountered
 * during the transfer of a Pool.  This allows the failure of a single request
 * to be isolated so that other requests in the same pool can still successfully
 * complete.
 *
 * @author  michael@guzzlephp.org
 */
class PoolRequestException extends HttpException implements \IteratorAggregate, \Countable
{
    /**
     * @var array Array of RequestException objects
     */
    protected $exceptions = array();

    /**
     * Set the request that caused the exception
     *
     * @param RequestInterface $request Request to set
     */
    public function addException(RequestException $request)
    {
        $this->exceptions[] = $request;
    }

    /**
     * Get the exceptions thrown during the Pool transfer
     *
     * @return array Returns an array of RequestException objects
     */
    public function getRequestExceptions()
    {
        return $this->exceptions;
    }

    /**
     * Get the total number of request exceptions
     *
     * @return int
     */
    public function count()
    {
        return count($this->exceptions);
    }

    /**
     * Allows array-like iteration over the request exceptions
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->exceptions);
    }
}