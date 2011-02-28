<?php

namespace Guzzle\Http\Message;

/**
 * Class to process responses
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface ResponseProcessorInterface
{
    /**
     * Process a response message
     *
     * @param RequestInterface $request Associated request
     * @param Response $response Response to process
     */
    public function processResponse(RequestInterface $request, Response $response);
}