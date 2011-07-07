<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\HttpException;

/**
 * Http request exception
 *
 * @author  michael@guzzlephp.org
 */
class RequestException extends HttpException
{
    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * Set the request that caused the exception
     *
     * @param RequestInterface $request Request to set
     */
    public function setRequest(RequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * Get the request that caused the exception
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }
}