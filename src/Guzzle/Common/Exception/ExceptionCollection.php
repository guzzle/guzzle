<?php

namespace Guzzle\Common\Exception;

/**
 * Collection of exceptions
 */
class ExceptionCollection extends \Exception implements GuzzleException, \IteratorAggregate, \Countable
{
    /** @var array Array of Exceptions */
    protected $exceptions = array();

    /**
     * Set all of the exceptions
     *
     * @param array $exceptions Array of exceptions
     *
     * @return self
     */
    public function setExceptions(array $exceptions)
    {
        $this->exceptions = $exceptions;

        return $this;
    }

    /**
     * Add exceptions to the collection
     *
     * @param ExceptionCollection|\Exception $e Exception to add
     *
     * @return ExceptionCollection;
     */
    public function add($e)
    {
        if ($this->message) {
            $this->message .= "\n";
        }

        if ($e instanceof self) {
            foreach ($e as $exception) {
                $this->exceptions[] = $exception;
                $this->message .= $e->getMessage() . "\n";
            }
        } elseif ($e instanceof \Exception) {
            $this->exceptions[] = $e;
            $this->message .= $e->getMessage();
        }

        $this->message = rtrim($this->message);

        return $this;
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
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->exceptions);
    }

    /**
     * Get the first exception in the collection
     *
     * @return \Exception
     */
    public function getFirst()
    {
        return $this->exceptions ? $this->exceptions[0] : null;
    }
}
