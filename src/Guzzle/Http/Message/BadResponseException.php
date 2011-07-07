<?php

namespace Guzzle\Http\Message;

/**
 * Http request exception thrown when a bad response is received
 *
 * @author  michael@guzzlephp.org
 */
class BadResponseException extends RequestException
{
    /**
     * @var Response
     */
    private $response;

    /**
     * Set the response that caused the exception
     *
     * @param Response $response Response to set
     */
    public function setResponse(Response $response)
    {
        $this->response = $response;
    }

    /**
     * Get the response that caused the exception
     *
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }
}