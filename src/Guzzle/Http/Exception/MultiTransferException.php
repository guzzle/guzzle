<?php

namespace Guzzle\Http\Exception;

use Guzzle\Common\Exception\ExceptionCollection;
use Guzzle\Http\Message\RequestInterface;

/**
 * Exception encountered during a multi transfer
 */
class MultiTransferException extends ExceptionCollection
{
    protected $successfulRequests = array();
    protected $failedRequests = array();

    /**
     * Get all of the requests in the transfer
     *
     * @return array
     */
    public function getAllRequests()
    {
        return array_merge($this->successfulRequests, $this->failedRequests);
    }

    /**
     * Add to the array of successful requests
     *
     * @param RequestInterface $request Successful request
     *
     * @return self
     */
    public function addSuccessfulRequest(RequestInterface $request)
    {
        $this->successfulRequests[] = $request;

        return $this;
    }

    /**
     * Add to the array of failed requests
     *
     * @param RequestInterface $request Failed request
     *
     * @return self
     */
    public function addFailedRequest(RequestInterface $request)
    {
        $this->failedRequests[] = $request;

        return $this;
    }

    /**
     * Get an array of successful requests sent in the multi transfer
     *
     * @return array
     */
    public function getSuccessfulRequests()
    {
        return $this->successfulRequests;
    }

    /**
     * Get an array of failed requests sent in the multi transfer
     *
     * @return array
     */
    public function getFailedRequests()
    {
        return $this->failedRequests;
    }
}
